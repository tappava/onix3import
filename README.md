This PHP script imports book metadata from ONIX 3.0 XML files into a MySQL database. It is designed for bulk import, supports new, updated, and deleted records, and excludes ebooks by default.

---

## Features

- **Scans a folder for ONIX 3.0 XML files**
- **Parses and imports book metadata**: title, ISBN, price, author(s), cover image, promotional text, description, author biography, language
- **Handles ONIX NotificationType**:
  - `01` – New: Inserts new records (ignores duplicates)
  - `03` – Update: Inserts or updates records
  - `05` – Delete: Removes records
- **Excludes ebooks** (ProductForm starting with `D`)
- **Uses prepared statements for security**
- **UTF-8 encoding support**

---

## Configuration

Edit these variables at the top of the script:

```php
$xmlFolder = '/vlb';         // Path to ONIX XML files
$dbHost = '127.0.0.1';      // MySQL host
$dbName = 'KATALOG';        // MySQL database name
$dbUser = 'root';           // MySQL user
$dbPass = 'password';       // MySQL password
$dbPort = '3311';           // MySQL port
```

---

## Usage

1. **Create the database table** (run once):

    ```sql
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
    ```

2. **Configure the script**  
   Set the correct folder and database credentials.

3. **Run the script**  
   ```
   php oniximport.php
   ```

---

## How It Works

- **Scans** the `$xmlFolder` for `.xml` files.
- **Parses** each file, extracting book data (excluding ebooks).
- **Imports** each product:
  - **New (`01`)**: `INSERT IGNORE` (skips duplicates)
  - **Update (`03`)**: `INSERT ... ON DUPLICATE KEY UPDATE`
  - **Delete (`05`)**: `DELETE FROM books WHERE record_reference=?`

---

## Customization

- **Include ebooks**: Remove the check for `ProductForm` starting with `D` in `parseOnixFile`.
- **Add more fields**: Extend the parsing logic and database schema as needed.

---

## Requirements

- PHP with `mysqli` and `SimpleXML` extensions
- MySQL database

---

## License

MIT License

---

## Contributing

Pull requests and issues are welcome!

---

## Example

```bash
php oniximport.php
```

---

## Troubleshooting

- **No data imported?**  
  Check database credentials, table schema, and XML file paths.
- **Ebooks missing?**  
  Remove the exclusion logic for `ProductForm` starting with `D`.
- **Encoding issues?**  
  Ensure your database and table use `utf8mb4`.

---

**Maintainer:**  
Sebastian
