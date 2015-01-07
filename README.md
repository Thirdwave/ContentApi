ContentApi
============================
Content API for Bolt CMS

Changelog
----------------------------
## 1.0.4
- Check if file exists before getting filesize and mimetype.

## 1.0.3
- Added filesize, extension, mimetype for files.
- Added support for imagelist values.

## 1.0.2
- Added custom exception and error handling.
- Added custom response type (for now only used for errors and exceptions).
- Added option to expand related items with expand querystring parameter.

## 1.0.1
- Added check for excluded contenttypes through config.
- Improved base column listing when specified for a specific contenttype.
- Added permission check for viewing content from api (for now based on anonymous role).