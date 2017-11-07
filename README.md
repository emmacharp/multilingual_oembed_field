# Field: Multilingual oEmbed #

> A multilingual version of the oembed_field extension

@see <http://oembed.com>

### SPECS ###

- The same as the [original extension](https://github.com/Solutions-Nitriques/oembed_field#specs).

### REQUIREMENTS ###

- Symphony CMS version 2.5.0 and up (as of the day of the last release of this extension)
- [oEmbed Field](https://github.com/Solutions-Nitriques/oembed_field) version 1.8.8 and up
- [Frontend Localisation](https://github.com/DeuxHuitHuit/frontend_localisation) 2.5 and up

### INSTALLATION ###

- `git clone` / download and unpack the tarball file
- (re)Name the folder **multilingual_oembed_field**
- Put into the extension directory
- Enable/install just like any other extension

See <http://getsymphony.com/learn/tasks/view/install-an-extension/>


### HOW TO USE ###

- Make sure that all requirements are met.
- After installation, add a Multilingual oEmbed field to a section
- Configure the field
	- Select at least one supported driver
	- You can add extra parameters to the oEmbed request's query string: this is usefull for settings embed sizes
- All the data will be available as xml in a datasource
- Use the `oembed` tag for embeding the resource into your frontend

#### Non-native ####

"Non-native" solutions like embed.ly are tested after all other "native" solutions. This will
allow you to enable both natives solution while being able to revert to a global fallback. If
other non-native solutions are added, please do not enable more than one because this may cause 
un-wanted behavior.

### LICENSE ###

[MIT](http://deuxhuithuit.mit-license.org)

Made with love in Montréal by [Deux Huit Huit](https://deuxhuithuit.com)

Copyright (c) 2015-2017
