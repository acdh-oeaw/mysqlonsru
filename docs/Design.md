# Design decisions so far

## Complicated SQL

The SQL that is _generated_ by the PHP code does not look optimized.
That is because the WHERE clauses are configured using some settings form the database
or some similar defaults specified in configuration statements using PHP arrays.
Nonetheless the statements proved to be fast enough unless there was some corner case situations.

## Indexes are specified in the DB table for a particular dictionary like collection

This enables the creator of some dictionary like collection to specify indexes she sees fit.
This makes any assumptions on the complexity of the "filters" impossible. Users can break the
search for some index. There is a default setting if no settings are in the DB table.

## Sorting results

Sorting results for transcribed languages has its own set of rules that are not incorporated as
collation into any DB. The collations provided mostly don't fit. The "non collation" bin also has
awkward effects. So the soting was implemented in PHP. That leads to a new problem: There is no
way to limit the results the DB has to return to a sane amount. So now bin collation is used again
for the special autocomp index. That unfortunately prevents "fuzzy search" (e. g. search for g, get
results with g, G, ž, ǧ, ġ, etc.)

## Not using MySQLs XPath capabilities for now

We don't use MySQLs XPath capabilities right now. They don't work for us. [There is code](https://github.com/acdh-oeaw/mysqlonsru/blob/master/common.php#L765)
which documents we tried. It was difficult to make up for
[the limitations](http://dev.mysql.com/doc/refman/5.7/en/xml-functions.html) (search for
"XPath Limitations"). What makes it useless for us for now is the fact that
with our current version of teh DB (MariaDB 5.5) we cannot tokenize the results
on the fly we may get using XPath expresseion. This could be solved by a full-text
search on the ExtractValues result. Seems this at least needs the creation
of a temporary table, full-text indexing can not be done on the fly.
This is by the way an entirely unportable feature even though other SQL DBs provide similar
functionalty but all with their own names and implementations.
