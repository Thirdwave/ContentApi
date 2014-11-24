ContentApi
============================
Content API for Bolt CMS

Installation
----------------------------
1. Install extension through CMS.

Configuration
----------------------------
`mounting_point: /api` 

Base url for API routes. The current major version of the API is appended to the mounting point (`/api/v1`).

`whitelist: [ ]`

By default the API can only be accessed by itself. Add an array of IP addresses to allow outside access to 
the API. You can also add the first parts of an IP address to allow a range of IP addresses to acccess the 
API. 

_Example:_

```
whitelist: ["192.168.", "213.84.151.25"]
```

`exclude: [ ]`

By default all contenttypes can be requested through the API. Supply an array of contenttypes that may not
be accessed by the API.

```
defaults:
    limit: 10
    order: -datepublish
```

Default settings to be used when requesting content from the API. Note that default settings for the
contenttype as defined in the contenttypes.yml are ignored. _This may change in future versions._