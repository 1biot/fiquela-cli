# FiQueLa CLI

[![CI](https://github.com/1biot/fiquela-cli/actions/workflows/ci.yml/badge.svg)](https://github.com/1biot/fiquela-cli/actions/workflows/ci.yml)
![Coverage](https://img.shields.io/badge/coverage-91.58%25-brightgreen)
![Tests](https://img.shields.io/badge/tests-295-blue)
![Assertions](https://img.shields.io/badge/assertions-618-blue)
![PHPStan](https://img.shields.io/badge/phpstan-level%208-brightgreen)

FiQueLa CLI is a command-line tool that allows users to execute SQL-like queries on structured data files (CSV, JSON,
XML, YAML, NEON, XLS/XLSX) or via the FiQueLa API.

**Table of contents**:

* [Installation](#installation)
* [Usage](#usage)
* [Local Mode](#local-mode)
* [API Mode](#api-mode)
* [Interactive Mode](#interactive-mode)
* [Non-Interactive Mode](#non-interactive-mode)
* [Configuration](#configuration)

## Installation

### Install script (recommended)

Downloads the PHAR and installs it to `/usr/local/bin/fiquela-cli`:

```bash
curl -fsSL https://raw.githubusercontent.com/1biot/fiquela-cli/main/install.sh | bash
```

Install a specific version:

```bash
curl -fsSL https://raw.githubusercontent.com/1biot/fiquela-cli/main/install.sh | bash -s -- v2.1.1
```

### Manual download

```bash
curl -fsSL https://github.com/1biot/fiquela-cli/releases/latest/download/fiquela-cli.phar -o /usr/local/bin/fiquela-cli
chmod +x /usr/local/bin/fiquela-cli
```

### Self-update

The PHAR version can update itself to the latest release:

```bash
fiquela-cli self-update
```

### Requirements

`php` >= `8.2` with extensions `readline`, `curl`, and `zlib`.

## Usage

```bash
fiquela-cli [options] [query]
```

### Options

| Parameter          | Shortcut | Description                                     |
|--------------------|----------|-------------------------------------------------|
| `--file`           | `-f`     | Path to the data file                           |
| `--file-type`      | `-t`     | File type (csv, xml, json, yaml, neon)          |
| `--file-delimiter` | `-d`     | CSV file delimiter (default `,`)                |
| `--file-encoding`  | `-e`     | File encoding (default `utf-8`)                 |
| `--memory-limit`   | `-m`     | Set memory limit, local mode only (e.g. `128M`) |
| `--connect`        | `-c`     | Connect to FiQueLa API                          |
| `--server`         | `-s`     | Server name/alias from `~/.fql/auth.json`       |
| `--user`           | `-u`     | API username (fallback)                         |
| `--password`       | `-p`     | API password (fallback)                         |
| `--help`           | `-h`     | Show help                                       |

### Mode Decision

- If `query` argument is provided: **Non-interactive mode** (outputs JSON to stdout)
- If `query` argument is omitted: **Interactive mode** (REPL with table display)
- If `--connect` is specified: **API mode** (queries run against FiQueLa API)
- If `--connect` is not specified: **Local mode** (queries run against local files)

## Local Mode

In local mode, FiQueLa CLI operates directly on files from the local filesystem.

### Examples

#### Run a query on a CSV file

```bash
fiquela-cli --file=data.csv "SELECT name, age FROM * WHERE age > 30 ORDER BY age DESC;"
```

#### Run a query with FQL syntax (file embedded in query)

```bash
fiquela-cli "SELECT name, age FROM csv(data.csv).* WHERE age > 30;"
```

#### Specify encoding and delimiter

```bash
fiquela-cli --file=data.csv --file-encoding=windows-1250 --file-delimiter=";" "SELECT * FROM *;"
```

#### Force file type

```bash
fiquela-cli --file=data.txt --file-type=csv "SELECT * FROM *;"
```

Notes:
- `--file-encoding` is used only for CSV and XML files.
- `--file-delimiter` is used only for CSV files.
- File type is auto-detected from the file extension, but can be overridden with `--file-type`.
- `--memory-limit` applies only to local mode.

## API Mode

In API mode, all queries are executed against a remote FiQueLa API server.

### Connecting

```bash
fiquela-cli --connect "SELECT * FROM xml(file.xml).channel.item;"
```

### Authentication

The CLI uses credentials stored in `~/.fql/auth.json`. This file must have permissions `0600`.

If `auth.json` does not exist or is empty, the CLI will interactively prompt you to add a server.

If `auth.json` has incorrect permissions, you must provide credentials via command-line options:

```bash
fiquela-cli --connect --server=https://api.example.com --user=admin --password='YourPass123!' "SELECT * FROM items;"
```

### Multiple Servers

If multiple servers are configured and `--server` is not specified, the CLI will interactively
prompt you to select a server.

```bash
# Use a specific server
fiquela-cli --connect --server=production "SELECT * FROM items;"

# Interactive server selection (when multiple servers exist)
fiquela-cli --connect
```

### Token Management

After successful authentication, the JWT token is stored in `~/.fql/sessions.json` (permissions `0600`).
The token is reused until it expires, avoiding repeated logins.

## Interactive Mode

Interactive mode starts when no `query` argument is provided. It works like a SQL client (similar to `mysql`).

```bash
# Local interactive mode
fiquela-cli --file=data.csv

# API interactive mode
fiquela-cli --connect
```

### Welcome Header

The CLI displays a detailed header showing the current mode:

```text
FiQueLa CLI v2.0.0

Mode:          LOCAL
Memory limit:  128M
File:          data.csv (2.3 MB)
Encoding:      utf-8
Delimiter:     ,

Commands end with ;. Type 'exit' or Ctrl+C to quit.
Type 'connect [server]' to switch to API mode, 'local' to switch to LOCAL mode.
fql>
```

Or for API mode:

```text
FiQueLa CLI v2.0.0

Mode:          API
Server:        https://fiquela.preved.to (production)

Commands end with ;. Type 'exit' or Ctrl+C to quit.
Type 'connect [server]' to switch to API mode, 'local' to switch to LOCAL mode.
fql>
```

### Query Input

Queries can span multiple lines. The query is executed when a semicolon is detected:

```text
fql> SELECT channel, SUM(budget) AS total_budget
  -> FROM json(marketing.json).*
  -> GROUP BY channel;
```

Special commands:

| Command              | Action                                                              |
|----------------------|---------------------------------------------------------------------|
| `exit` / `Ctrl+C`   | Quit the application                                                |
| `info`               | Re-display the welcome header                                       |
| `clear`              | Clear the screen, reset query buffer and re-display the header      |
| `connect [server]`   | Switch to API mode (uses server from `auth.json`)                   |
| `local`              | Switch back to LOCAL mode                                           |
| `self-update`        | Update to latest version (PHAR only)                                |

#### Mode Switching

You can switch between LOCAL and API modes at any time during an interactive session:

```text
fql> connect production
FiQueLa CLI v2.0.0

Mode:          API
Server:        https://api.example.com (production)
...

fql> SELECT * FROM xml(file.xml).channel.item;
...

fql> local
FiQueLa CLI v2.0.0

Mode:          LOCAL
...
```

When switching to API mode:
- If `auth.json` contains a single server, it connects automatically
- If multiple servers exist, you must specify the name: `connect production`
- If the server name is not found, available servers are listed in the error message

### Result Paging

Results are displayed in a paginated table (25 items per page):

```text
+-----------+---- Page 1/3 +---------------+
| channel   | total_budget | total_revenue |
+-----------+--------------+---------------+
| organic   | 12060966.38  | 133104578.3   |
| promotion | 13495668.71  | 136182504.28  |
...
+-------- Showing 1-25 from 60 rows --------+
0.0882 sec, memory 4.75 MB, memory (peak) 4.89 MB
```

### Paging Controls

| Command       | Action                          |
|---------------|---------------------------------|
| `Enter` / `:n` | Next page (wraps around)      |
| `:b`          | Previous page (wraps around)    |
| `:l`          | Jump to last page               |
| `:f`          | Jump to first page              |
| `/text`       | Highlight search text on page   |
| `:q`          | Quit result paging              |
| `<number>`    | Jump to specific page number    |

### Query History

- **Local mode**: History is stored in `~/.fql/history`
- **API mode**: History is stored in `~/.fql/history-api`, and is downloaded from the API server when entering interactive mode

## Non-Interactive Mode

When a `query` argument is provided, the CLI runs in non-interactive mode and outputs raw JSON to stdout.

```bash
# Local query with JSON output
fiquela-cli --file=data.csv "SELECT name, age FROM * WHERE age > 30;"

# API query with JSON output
fiquela-cli --connect "SELECT * FROM xml(file.xml).channel.item WHERE price < 250;"
```

Output is always compact JSON, suitable for piping to `jq` or other tools:

```bash
fiquela-cli --file=data.csv "SELECT name FROM *;" | jq '.[].name'
```

For API mode, if the query result has multiple pages, the CLI automatically exports all results
via the API export endpoint before outputting.

## Configuration

### Directory Structure

```
~/.fql/
  auth.json       # Server credentials (permissions: 0600)
  sessions.json   # JWT tokens (permissions: 0600)
  history         # Local mode query history
  history-api     # API mode query history
```

### auth.json Format

```json
[
  {
    "name": "production",
    "url": "https://api.example.com",
    "user": "admin",
    "secret": "YourPassword123!"
  },
  {
    "name": "local",
    "url": "http://localhost:6917",
    "user": "dev",
    "secret": "DevPass123!"
  }
]
```

### sessions.json Format

```json
{
  "https://api.example.com": {
    "token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_at": 1700000000
  }
}
```
