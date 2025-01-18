# Project: CSV to Kubera API Synchronization

This project facilitates the synchronization of asset data from multiple CSV files to the kubera API. Each CSV file corresponds to a specific provider, defined in a structured configuration, and processed in batches. The system handles updates, missing assets, and data archiving efficiently.

## Features

- **Dynamic Configuration**: Each provider has its own configuration (`config.json`) for mapping CSV columns to API attributes and defining composite keys.
- **CSV Processing**: Supports multiple providers, with CSV files stored in `data/{provider}/` folders.
- **API Integration**: Updates existing assets, flags missing assets, and synchronizes data with the API.
- **Error Handling**: Logs errors during API calls and processing.
- **Processed Files Management**: Archives processed CSV files in a `processed` subfolder, appending a timestamp to the filenames.

## Project Structure

```
.
├── config/
│   ├── bank_1/
│   │   └── config.json
│   ├── bank_2/
│   │   └── config.json
│   └── ...
├── data/
│   ├── bank_1/
│   │   ├── somecsvfile.csv
│   │   └── processed/
│   ├── bank_2/
│   │   ├── anothercsvfile.csv
│   │   └── processed/
│   └── ...
├── logs/
│   └── sync.log
├── vendor/
├── sync.php
└── README.md
```

### Configuration (`config.json`)
Each provider must define a `config.json` file in its configuration folder. Example:

```json
{
  "columnMapping": {
    "Client number": "clientNumber",
    "Asset Type": "assetType",
    "Asset class": "assetClass",
    "Valor number": "valorNumber",
    "ISIN": "isin",
    "Bloomberg ticker": "bloombergTicker",
    "Asset description": "assetDescription",
    "Value in curr.": "value"
  },
  "compositeKeyColumns": [
    "clientNumber",
    "assetType",
    "assetClass",
    "valorNumber",
    "isin",
    "bloombergTicker",
    "assetDescription"
  ],
  "csvValueColumn": "Value in curr.",
  "csvAssetDescriptionColumn": "assetDescription"
}
```

### Logs
All logs are stored in the `logs/` directory in the `sync.log` file.

## Setup Instructions

### Prerequisites
- **PHP**: Version 7.4 or higher.
- **Composer**: Installed globally.
- **API Credentials**: Obtain API Key and Secret.

### Installation
1. **Clone the Repository**:
   ```bash
   git clone <repository_url>
   cd <project_directory>
   ```

2. **Install Dependencies**:
   ```bash
   composer install
   ```

3. **Environment Configuration**:
   Create an `.env` file in the `config/` directory with the following content:
   ```env
   API_KEY=your_api_key
   API_SECRET=your_api_secret
   API_BASE_URL=https://api.kubera.com
   ```

4. **Provider Configuration**:
   - Add a folder for each provider in `config/`.
   - Include a `config.json` file as per the structure defined above.
   - Place the corresponding CSV files in `data/{provider}/`.

5. **Create Directories**:
   Ensure the following directories exist:
   ```bash
   mkdir -p logs
   mkdir -p data/{provider}/processed
   ```

## Usage

1. **Run the Synchronization Script**:
   ```bash
   php sync.php
   ```

2. **Check Logs**:
   Review the `logs/sync.log` file for synchronization details.

3. **Verify Processed Files**:
   Processed CSV files will be moved to `data/{provider}/processed/` with a timestamp in the filename.

## Notes
- Ensure proper permissions for the `data/` and `logs/` directories.
- Validate the `config.json` file for each provider to avoid errors.
- Handle sensitive data like API keys securely.

## Troubleshooting

### Common Issues
1. **Missing Configurations**:
   Ensure `config.json` and corresponding CSV files are correctly placed.

2. **API Errors**:
   Check the `sync.log` file for detailed error messages.

3. **File Permissions**:
   Ensure the script has read/write permissions for the `data/` and `logs/` directories.

For further assistance, contact the development team.

