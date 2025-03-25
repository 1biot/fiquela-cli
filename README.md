# FiQueLa CLI

FiQueLa CLI is a command-line tool that allows users to execute SQL-like queries on structured data files, including
CSV, JSON, XML, YAML, and NEON.
This tool is powered by the [FiQueLa](https://github.com/1biot/FiQueLa/blob/main/docs/file-query-language.md) library,
which provides SQL-inspired query capabilities for various data formats.

## Requirements

- PHP 8.1+
- Git
- Composer

## Installation

One line installation:

```bash
curl -sSL https://raw.githubusercontent.com/1biot/fiquela-cli/main/install.sh | bash
```

Clone the repository and install dependencies:

```bash
git clone https://github.com/1biot/fiquela-cli.git
cd fiquela-cli
composer install
```

If you want to make `fiquela-cli` globally accessible, create a symbolic link:

```bash
ln -s $(pwd)/bin/fiquela-cli /usr/local/bin/fiquela-cli
chmod +x /usr/local/bin/fiquela-cli
```

## Usage

### Running the Command

```bash
fiquela-cli [options] [query]
```

### Options

| Parameter          | Shortcut | Description                            |
|--------------------|----------|----------------------------------------|
| `--preview`        | `-p`     | Show file contents                     |
| `--url`            | `-u`     | URL of the data file                   |
| `--file`           | `-f`     | Path to the data file                  |
| `--file-type`      | `-t`     | File type (csv, xml, json, yaml, neon) |
| `--file-delimiter` | `-d`     | CSV file delimiter (default `,`)       |
| `--file-encoding`  | `-e`     | File encoding (default `utf-8`)        |
| `--memory-limit`   | `-m`     | Set memory limit (e.g. `128M`)         |
| `--help`           | `-h`     | Show the help for fiquela-cli          |

### Examples

#### 1. Preview File Contents

```bash
fiquela-cli --preview --file=data.csv
```

#### 2. Run Query on CSV File

```bash
fiquela-cli --file=data.csv --file-type=csv "SELECT name, age FROM users WHERE age > 30 ORDER BY age DESC;"
```

#### 3. Interactive Mode

```bash
fiquela-cli
```

In interactive mode, you can enter SQL-like queries and get results in a table format. To exit, type `exit` or press `Ctrl+C`.

## Interactive Mode

Interactive mode supports query history and pagination for results.

```bash
fiquela-cli
```

```text
Welcome to FiQueLa interactive mode. Commands end with ;.

Memory limit: 128M

Type 'exit' or 'Ctrl-c' to quit.
fql>
```

### Controls:

- `[Enter]` or `n` – Next page
- `b` – Previous page
- `l` – Last page
- `f` – First page
- `j` – Export to JSON (only available in interactive mode)
- `exit` or `q` – Quit

### Example

```text
fql> select count(id) as count, industry, founded, from (filtered_by_industry.json) group by industry, founded order by count desc;

SELECT
  COUNT(id) AS count ,
  industry ,
  founded
FROM [json](filtered_by_industry.json) .
GROUP BY industry , founded
ORDER BY count DESC

+-------+---------- Page 1/9 ---------+---------+
| count | industry                    | founded |
+-------+-----------------------------+---------+
| 28    | Internet                    | 1974    |
| 24    | Computer Networking         | 1999    |
| 22    | Internet                    | 2019    |
| 21    | Graphic Design / Web Design | 2002    |
| 21    | Internet                    | 2016    |
| 20    | Internet                    | 1986    |
| 20    | Graphic Design / Web Design | 1991    |
| 20    | Computer Networking         | 2003    |
| 19    | Graphic Design / Web Design | 2008    |
| 19    | Graphic Design / Web Design | 1982    |
| 19    | Computer / Network Security | 2017    |
| 18    | Graphic Design / Web Design | 1971    |
| 18    | Graphic Design / Web Design | 1983    |
| 18    | Graphic Design / Web Design | 2000    |
| 18    | Internet                    | 2004    |
| 18    | Internet                    | 1970    |
| 17    | Internet                    | 2005    |
| 17    | Computer Networking         | 2008    |
| 17    | Computer Networking         | 2000    |
| 17    | Computer Networking         | 1988    |
| 17    | Computer / Network Security | 1981    |
| 17    | Internet                    | 1977    |
| 17    | Computer / Network Security | 1996    |
| 17    | Computer / Network Security | 1984    |
| 17    | Graphic Design / Web Design | 2014    |
+-------+- Showing 1-25 from 212 rows +---------+
0.0660 sec, memory 3.4632 (emalloc), memory (peak) 5.0733 (emalloc)
Press [Enter] or [:n] for next page, [:b] for previous, [:l] for last, [:f] for first, [/text] for search text in page [:e] for export results to file, [:q] to quit
```

### Export (Interactive Mode Only)

In interactive mode, users can export query results to JSON or CSV by pressing `:e`. The tool will prompt for
a file name and save the output in chosen format.
This feature is currently not yet available as a standalone command-line option.

## Output Format

Query results are displayed in a tabular format with performance metrics:

```bash
0.0345 sec, memory 5.4MB, memory (peak) 7.2MB
```

## About FiQueLa

FiQueLa is a lightweight PHP library designed for querying structured data files using SQL-like syntax.
It supports various formats, including CSV, JSON, XML, YAML, and NEON. This CLI tool utilizes FiQueLa to provide
an efficient way to interact with structured data directly from the terminal.
For more information, visit the [FiQueLa GitHub repository](https://github.com/your-repo-link).

## Conclusion

FiQueLa CLI is a powerful tool for querying structured data files using SQL-like commands.
It provides an interactive environment, efficient memory management, and result export functionality.
For more details or contributions, visit the GitHub repository.
