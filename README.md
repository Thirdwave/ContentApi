ContentApi
============================
Content API for Bolt CMS

Changelog
----------------------------

## 1.1.15
- [Bugfix] Meerdere subrelaties ophalen.

## 1.1.14
- [Feature] Ophalen van relaties nu ook met subrelaties.

## 1.1.13
- [Bugfix] Tenzij expliciet gevraagd alleen gepubliceerde items teruggeven bij opvragen enkel record.

## 1.1.12
- [Feature] Added an option to request items based on related item.

## 1.1.11
- [Feature] Local IP address is whitelisted.

## 1.1.10
- [Bugfix] Only get published content by default.

## 1.1.9
- [Bugfix] Turn of html snippets for API calls.

## 1.1.8
- [Bugfix] Correct default sorting for non grouped content.

## 1.1.7
- [Bugfix] Paging is by default set to `true` instead of `1`.

## 1.1.6
- [Bugfix] Related content with selected resultset.

## 1.1.5
- [Bugfix] Correction in default group sorting.

## 1.1.4
- [Bugfix] Correct status code for taxonomy.

## 1.1.3
- [Feature] When sorting by the default order 'grouped' content will be sorted by index.

## 1.1.2
- [Bugfix] Config is now public property.

## 1.1.1
- [Feature] Added call for listing results of more contenttypes.
- [Feature] Index call now returns the version of the Content API.
- General code cleanup.

## 1.1.0
- [Feature] Added call for returning telephone book like filters with count for a contenttype and field. 
- [Feature] Added call for returning field definition for a contenttype.

## 1.0.11
- [Feature] Allow random sorting for listing and searching with order=RANDOM

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
