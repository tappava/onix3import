/**
 * ONIX 3.0 XML Importer for MySQL
 *
 * This script scans a specified folder for ONIX 3.0 XML files, parses each file,
 * and imports book metadata into a MySQL database. It excludes ebooks and handles
 * new, updated, and deleted records based on ONIX NotificationType.
 *
 * Configuration:
 * - $xmlFolder: Path to the folder containing ONIX XML files.
 * - $dbHost, $dbName, $dbUser, $dbPass, $dbPort: MySQL connection parameters.
 *
 * Functions:
 * - parseOnixFile($filePath): Parses an ONIX XML file and extracts product data
 *   (title, ISBN, price, author(s), cover image link, promotional text, description,
 *   author biography, language). Skips ebooks (ProductForm starting with 'D').
 * - importProduct($mysqli, $product): Imports a product into the database.
 *   Handles three NotificationTypes:
 *     '01' - New: Inserts new record (ignores duplicates).
 *     '03' - Update: Inserts or updates record based on unique key.
 *     '05' - Delete: Removes record from database.
 *
 * Main Process:
 * - Scans the XML folder for files.
 * - For each file, parses products and imports them into the database.
 *
 * Example Table Schema:
 * - Provided as a comment for initial database setup.
 *
 * Usage:
 * - Configure paths and database credentials.
 * - Ensure the 'books' table exists.
 * - Run the script to import ONIX data.
 *
 * Note:
 * - Uses mysqli prepared statements for database operations.
 * - Handles UTF-8 encoding.
 */
<?php
// Configuration
$xmlFolder = '/vlb';
$dbHost = '127.0.0.1';
$dbName = 'KATALOG';
$dbUser = 'root';
$dbPass = 'password';
$dbPort = '3311';

// Connect to MySQL using mysqli
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($mysqli->connect_error) {
    die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// Helper: Parse ONIX 3.0 file
function parseOnixFile($filePath) {
    $xml = simplexml_load_file($filePath, null, LIBXML_NOCDATA);
    $products = [];
    foreach ($xml->Product as $product) {
        // Check if product is an ebook
        // If you want to include ebooks, remove the following lines
        $isEbook = false;
        if (isset($product->DescriptiveDetail->ProductForm)) {
            $productForm = (string)$product->DescriptiveDetail->ProductForm;
            if (strpos($productForm, 'D') === 0) {
                $isEbook = true;
            }
        }
        if ($isEbook) {
            continue;
        }
        //

        $recordReference = (string)$product->RecordReference;
        $notificationType = (string)$product->NotificationType;
        $title = (string)$product->DescriptiveDetail->TitleDetail->TitleElement->TitleText;
        $isbn = null;
        if (isset($product->ProductIdentifier)) {
            foreach ($product->ProductIdentifier as $identifier) {
            if (isset($identifier->ProductIDType) && (string)$identifier->ProductIDType === '03') {
                $isbn = (string)$identifier->IDValue;
                break;
            }
            }
        }

        // Price in Germany (EUR)
        $price = null;
        if (isset($product->ProductSupply->SupplyDetail->Price)) {
            foreach ($product->ProductSupply->SupplyDetail->Price as $p) {
            if ((string)$p->CurrencyCode === 'EUR' && ((string)$p->CountryCode === 'DE' || !isset($p->CountryCode))) {
                $price = (string)$p->PriceAmount;
                break;
            }
            }
        }

        // Autor(en)
        $authors = [];
        if (isset($product->DescriptiveDetail->Contributor)) {
            foreach ($product->DescriptiveDetail->Contributor as $contributor) {
            if (isset($contributor->PersonName)) {
                $authors[] = (string)$contributor->PersonName;
            }
            }
        }
        $author = implode(', ', $authors);

        // Coverlink
        $coverlink = null;
        if (isset($product->CollateralDetail->SupportingResource)) {
            foreach ($product->CollateralDetail->SupportingResource as $resource) {
            if (isset($resource->ResourceContentType) && (string)$resource->ResourceContentType === '01') {
                if (isset($resource->ResourceVersion->ResourceLink)) {
                $coverlink = (string)$resource->ResourceVersion->ResourceLink;
                break;
                }
            }
            }
        }

        // Zusatztext (usually PromotionText)
        $zusatztext = null;
        if (isset($product->CollateralDetail->TextContent)) {
            foreach ($product->CollateralDetail->TextContent as $textContent) {
            if (isset($textContent->TextType) && ((string)$textContent->TextType === '02')) {
                $zusatztext = (string)$textContent->Text;
                break;
            }
            }
        }

        // Inhalt (usually Description)
        $inhalt = null;
        if (isset($product->CollateralDetail->TextContent)) {
            foreach ($product->CollateralDetail->TextContent as $textContent) {
            if (isset($textContent->TextType) && ((string)$textContent->TextType === '03')) {
                $inhalt = (string)$textContent->Text;
                break;
            }
            }
        }

        // Autorenportrait (usually BiographicalNote)
        $autorenportrait = null;
        if (isset($product->CollateralDetail->TextContent)) {
            foreach ($product->CollateralDetail->TextContent as $textContent) {
            if (isset($textContent->TextType) && ((string)$textContent->TextType === '04')) {
                $autorenportrait = (string)$textContent->Text;
                break;
            }
            }
        }

        // Sprache (Language)
        $language = null;
        if (isset($product->DescriptiveDetail->Language)) {
            foreach ($product->DescriptiveDetail->Language as $lang) {
            if (isset($lang->LanguageRole) && (string)$lang->LanguageRole === '01') {
                $language = (string)$lang->LanguageCode;
                break;
            }
            }
        }

        $products[] = [
            'recordReference'   => $recordReference,
            'notificationType'  => $notificationType,
            'title'             => $title,
            'isbn'              => $isbn,
            'price'             => $price,
            'author'            => $author,
            'coverlink'         => $coverlink,
            'zusatztext'        => $zusatztext,
            'inhalt'            => $inhalt,
            'autorenportrait'   => $autorenportrait,
            'language'          => $language
        ];
    }
    return $products;
}

// Helper: Import product to DB using mysqli
function importProduct($mysqli, $product) {
    switch ($product['notificationType']) {
        case '01': // New
            $stmt = $mysqli->prepare(
            "INSERT IGNORE INTO books 
            (record_reference, isbn, title, price, author, coverlink, zusatztext, inhalt, autorenportrait, language) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
            'ssssssssss',
            $product['recordReference'],
            $product['isbn'],
            $product['title'],
            $product['price'],
            $product['author'],
            $product['coverlink'],
            $product['zusatztext'],
            $product['inhalt'],
            $product['autorenportrait'],
            $product['language']
            );
            $stmt->execute();
            $stmt->close();
            break;
        case '03': // Update
            $stmt = $mysqli->prepare(
            "INSERT INTO books 
                (isbn, title, price, author, coverlink, zusatztext, inhalt, autorenportrait, language, record_reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                isbn=VALUES(isbn),
                title=VALUES(title),
                price=VALUES(price),
                author=VALUES(author),
                coverlink=VALUES(coverlink),
                zusatztext=VALUES(zusatztext),
                inhalt=VALUES(inhalt),
                autorenportrait=VALUES(autorenportrait),
                language=VALUES(language)"
            );
            $stmt->bind_param(
            'ssssssssss',
            $product['isbn'],
            $product['title'],
            $product['price'],
            $product['author'],
            $product['coverlink'],
            $product['zusatztext'],
            $product['inhalt'],
            $product['autorenportrait'],
            $product['language'],
            $product['recordReference']
            );
            $stmt->execute();
            $stmt->close();
            break;
        case '05': // Delete
            $stmt = $mysqli->prepare("DELETE FROM books WHERE record_reference=?");
            $stmt->bind_param('s', $product['recordReference']);
            $stmt->execute();
            $stmt->close();
            break;
        // Add more cases if needed
    }
}

// Scan folder for XML files
$files = glob($xmlFolder . '/*.xml');
sort($files, SORT_STRING);
foreach ($files as $file) {
    echo "Importing file: " . basename($file) . "\n";
    $products = parseOnixFile($file);
    foreach ($products as $product) {
        importProduct($mysqli, $product);
    }
}

// Example table creation (run once)
/*
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_reference VARCHAR(255) UNIQUE,
    isbn VARCHAR(32),
    title VARCHAR(255),
    price VARCHAR(32),
    author VARCHAR(255),
    coverlink VARCHAR(512),
    zusatztext TEXT,
    inhalt TEXT,
    autorenportrait TEXT,
    language VARCHAR(16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/
?>