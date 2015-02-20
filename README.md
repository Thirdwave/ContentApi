ContentApi
============================
Content API for Bolt CMS

Changelog
----------------------------

## 1.1.0
- Added call for returning telephone book like filters with count for a contenttype and field. 
- Added call for returning field definition for a contenttype.

## 1.0.11
- Allow random sorting for listing and searching with order=RANDOM

## 1.0.10
- Moved accept header for CORS
- [Bugfix] Search works with filter and extra where parameters

## 1.0.9
- Added whitelist false for open access
- Added accept header for CORS
- Fixed bug for sqlite
- Fixed version number

## 1.0.8
- Fix for video url's.

## 1.0.7
- Added record parsing for getting YouTube ids from the video url.

## 1.0.6
- Added route for adding content.

## 1.0.5
- Added record value filelist.
- Fixed bug where custom list of fields values did not process correctly.

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
