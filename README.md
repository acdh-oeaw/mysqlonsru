# mysqlonsru

FCS/SRU 1.2 glossary or dictionary endpoint using mysql as storage backend

## Setting up a MySQL/MariaDB based FCS/SRU 1.2 endpoint

* This project has to be in the same directory as [utils-php](https://github.com/acdh-oeaw/utils-php).
This component also has dependencies.
* For the [common configuration file](https://github.com/acdh-oeaw/utils-php/blob/master/config.php.dist)
see that project. A template for specifying the DB credentials is part of that file.

## Classes

* A [common base class](https://github.com/acdh-oeaw/mysqlonsru/blob/master/common.php) for feetching some data from the database and putting it into some XML template.
* A [subclass for handling glossary type data sources](https://github.com/acdh-oeaw/mysqlonsru/blob/master/GlossaryOnSRU.php)
* A [subclass of the latter that handles glossaries that are filtered by some fixed criteria](https://github.com/acdh-oeaw/mysqlonsru/blob/master/FilteredGlossaryOnSRU.php)
(e. g. the vocabulary that is part of some languages course)
* Some pseudo subclasses for handling other types of data sources:
** Bibliographic entries
** Language profiles for some geographical region
** Sample texts

## Database schema

The [database schema used](https://github.com/acdh-oeaw/vleserver/blob/master/module/wde/src/wde/V2/Rest/Dicts/DictsResource.php#L231) can be found in the sources for the [vleserver project](https://github.com/acdh-oeaw/vleserver) which is used for entering the
data into the database.

## Part of corpus_shell

Depends on [vLIB](https://github.com/acdh-oeaw/vLIB) and [utils-php](https://github.com/acdh-oeaw/utils-php). See the umbrella project [corpus_shell](https://github.com/acdh-oeaw/corpus_shell).

## More docs

* [TODO](https://github.com/acdh-oeaw/utils-php/blob/master/docs/TODO.md)
* [Design](https://github.com/acdh-oeaw/utils-php/blob/master/docs/Design.md): A document about design decissions.
