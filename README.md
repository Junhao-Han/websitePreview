# Website Preview Plugin for OJS

Website Preview is a generic plugin for Open Journal Systems (OJS) that lets editorial users preview static website projects submitted as ZIP files.

The plugin is designed for submissions where the scholarly work includes a small static website, interactive essay, or web-based project. Authors upload the project as a ZIP file, and users with access to the submission workflow can open it from the submission page using the **Website** button.

## Features

- Adds a **Web Project** file kind to the submission file type list.
- Detects ZIP files that contain an `index.html` entry point.
- Adds a **Website** button to eligible submission workflow pages.
- Opens the submitted website in a sandboxed preview page.
- Serves static assets from the ZIP, including HTML, CSS, JavaScript, images, fonts, audio, and video.
- Supports projects where `index.html` is inside a folder, such as `project/index.html`.

## Requirements

- OJS 3.3.x
- PHP ZIP support (`ext-zip` / `ZipArchive`)

This plugin has been developed and tested against OJS 3.3.0-21.

## Installation

Install the plugin as a generic plugin:

```sh
cd /path/to/ojs
git clone https://github.com/Junhao-Han/websitePreview.git plugins/generic/websitePreview
```

Then register the plugin version:

```sh
php lib/pkp/tools/installPluginVersion.php plugins/generic/websitePreview/version.xml
```

Enable the plugin in OJS:

```text
Settings > Website > Plugins > Website Preview
```

## Usage

1. Start a new submission.
2. Upload the website project as a ZIP file.
3. Make sure the ZIP file includes an `index.html` file.
4. Choose **Web Project** as the file kind.
5. Open the submission workflow or author dashboard.
6. Click **Website** to preview the submitted project.

The **Website** button opens the website file for the current workflow stage. If that stage does not include a valid ZIP file with an `index.html` file, the preview opens as a blank page.

## How It Works

The plugin adds its own OJS routes:

- `websitePreview/status/{submissionId}/{stageId}` checks whether the current workflow stage has a viewable website ZIP.
- `websitePreview/view/{submissionId}/{stageId}` opens the preview page.
- `websitePreview/asset/{submissionId}/{submissionFileId}/{path}` serves files from the extracted ZIP.

## License

This plugin is released under the GNU General Public License v3.0. See `LICENSE` for details.
