# Website Preview Plugin for OJS

Website Preview is a generic plugin for Open Journal Systems (OJS) that adds a preview for static website projects submitted as ZIP files.

It was initially developed for Encounters in Theory and History of Education, a journal hosted by Queen's University Library, where web-based scholarship, interactive essays, static exhibits, and similar projects may need to be reviewed inside OJS. Authors upload the project as a **Web Project** ZIP file, and editors can open it from the workflow page with a **Website** button.

## Requirements

- OJS 3.3.x
- PHP ZIP support (`ext-zip` / `ZipArchive`)

Developed against OJS 3.3.0-21.

## Installation

Install it as a generic plugin:

```sh
cd /path/to/ojs
git clone https://github.com/Junhao-Han/websitePreview.git plugins/generic/websitePreview
php lib/pkp/tools/installPluginVersion.php plugins/generic/websitePreview/version.xml
```

Enable it in:

```text
Settings > Website > Plugins > Website Preview
```

## Usage

Upload the website project as a ZIP file, choose **Web Project** as the file kind, and include an `index.html` entry point. Then open the submission workflow and click **Website**.

The preview uses the latest **Web Project** ZIP available in the current workflow stage. Other file types are ignored.

## Automatic Journal Changes

When enabled, the plugin adds the pieces the journal needs to accept web projects:

- A **Web Project** file type for submission files.
- An instruction in the submission checklist asking authors to upload web projects as ZIP files with an `index.html` file.

These settings are not removed automatically when the plugin is disabled.

## Notes

The ZIP should contain one clear `index.html` entry point. A root `index.html` is preferred. If there are multiple nested `index.html` files and no root entry point, the plugin will show an error instead of guessing which one to use.

The preview is intended for static websites: HTML, CSS, JavaScript, images, fonts, audio, and video. Server-side code is not executed.

Projects are shown in a sandboxed preview frame. The plugin allows common external static resources, such as CDN scripts and images, but blocks form submission, embedded objects, and background network requests from the preview.

Very large ZIP files, unsafe paths, and unsupported file types are rejected.

## License

This plugin is released under the GNU General Public License v3.0. See `LICENSE` for details.
