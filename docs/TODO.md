# Things that need to be done to make this project future proof

* The remaining pseudo classes should be converted to real PHP classes
* This component should be described using [composer](https://getcomposer.org) and then imported using it.
* This component should use [composer](https://getcomposer.org) to fetch its dependencies
* This component relies on global variables for configuration. They should be replaced with something better suited
* There should be PHPUnit tests
* Improve the XPath to SQL converter as much as possible
* Use the XPath to SQL converter to limit the number of results from the database with some part of the XPath.
Then use a really XPath aware tool like \DOMXpath to do the rest.
* There should be a way to filter for two (or more) criteria using AND/OR in the FCS/SRU syntax. E. g. searching a
bibliographic entry for author and year.
* The people that worked on a particular entry or on entries displayed should be in visible in the result by full name.
Only the username is there now.
* Files are to long they should be split up
