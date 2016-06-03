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
