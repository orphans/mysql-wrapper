# A Simple MySQL/MariaDB Wrapper

A database class that we like to use within small rapid development tasks. It won't suit everyone but resonates with the way we like to interact with the MySQL/MariaDB.

The class utilises the PHP Data Objects (PDO) extension and the primary aim is to reduce repetitive lines of code.

## Installation

### Composer

This is the preferred method of installation.

Add the following to your `composer.json` file:

    {
      "require" : {
        "orphans/mysql-wrapper" : "0.1.*"
      }
    }

Then install/update your composer project as normal. Remember to include the Composer autoloader:

    require 'vendor/autoload.php';

### Git

You can also include this in any Git project as follows:

    git clone https://github.com/orphans/mysql-wrapper.git
    git submodule init
    git submodule update

Remembering that you will need to include `mysql_wrapper.class.php` in your project's code.

### Unmanaged

If you would rather not have any externally referenced code in your project you may simply download the `mysql_wrapper.class.php` file and include it in your project. This isn't the preferred way though.

## Setup

The code itself will be better documented later but for now the following examples illustrate various use cases.

### Connect

Create an instance with the following snippet:

    $db = new MYSQL_WRAPPER();
    $db->connect(array(
    	'host' => '127.0.0.1', // hostname or IP
    	'port' => '3306', // optional, defaults to 3306
    	'username' => 'some_user',
    	'password' => 'some_pass',
    	'database' => 'some_db'
    ));

or, for a socket:

    $db = new MYSQL_WRAPPER();
    $db->connect(array(
    	'socket' => '/path/to/mysql.sock',
    	'username' => 'some_user',
    	'password' => 'some_pass',
    	'database' => 'some_db'
    ));

### Singleton model

Although it has potential limitations for a DB class (no multiple connections), in the vast majority of cases this a convenient way to manage things.

So if you you need to access the class where it's outside of the current scope use the following code rather than globalising the instance or passing it between functions.

    $db = MYSQL_WRAPPER::get_singleton();

## Query Helpers

Queries are send/received as arrays where practical, but not at the expense of flexibility. So we do not shy away from writing SQL where that makes more sense; most notably for SELECT queries.

When writing queries with user input that requires sanitisation you can use placeholders `:placeholder` then supply the value in a subsequent array parameter. That will make more sense in the examples below!

If you supply the string value `'NOW()'` or `'NULL'` in an INSERT or UPDATE it will be converted into the special meaning when sent to MySQL.

### Selects

Selects return results as an array, or FALSE on failure.

There are two methods `select()` and `select_single()`. Both work in the same way except the latter only ever returns a single-dimension array containing the first result.

    $db->select("SELECT * FROM `orders`");

    $db->select("SELECT * FROM `users` WHERE `name` = :name", [ 'name' => 'John Smith' ]);

### Inserts

Insert operations are done by sending a table reference and an array of fields => values.

This method will return the new row's ID, or else FALSE on failure.

    $db->insert('users', [
    	'name' => 'John Smith',
    	'email' => 'john.smith@somedomain.com',
    	'last_updated' => 'NOW()',
    ]);

You can tell MySQL to ignore errors by adding an extra TRUE parameter to the end of the method call.

    $db->insert('users', [
    	'name' => 'John Smith',
    	'email' => 'john.smith@somedomain.com',
    	'last_updated' => 'NOW()',
    ], TRUE);


### Updates

Updates work in a similar way to inserts except you pass a WHERE clause too. The first part of the clause (parameter 3) is the actual where clause, with placeholders used for sanitisation as described above.

This method will return TRUE or FALSE depending on outcome status.

    $db->update('users', [
    	'email' => 'john.smith@somedomain.com',
    	'last_updated' => 'NOW()',
    ], "`id` = :id", [
    	'id' => 1
    ]);

### Insert or update on duplicate

Same as an insert operation but with a second array of update fields if a duplicate row already exists.

This method will return TRUE or FALSE depending on outcome status.

    $db->insert_or_update('users', [
    	'name' => 'John Smith',
    	'email' => 'john.smith@somedomain.com',
    	'last_updated' => 'NOW()',
    ], [
    	'name' => 'John Smith',
    	'last_updated' => 'NOW()',
    ]);

### Deletions

Just needs a table name and a WHERE clause which uses placeholders used for sanitisation as described above.

This method will return TRUE or FALSE depending on outcome status.


    $db->delete('users', "`id` = :id", [
    	'id' => 1
    ]);


## Other Queries

For all other queries you can call the `query()` method which accepts a SQL query and an array of :variable replacements. See [this page](http://php.net/manual/en/pdostatement.execute.php) for a reminder of how these replacements work.

    $db->query('ALTER TABLE `users` ADD COLUMN ...');

## Closing Notes

There are still some special purpose solutions in the class for converting date formats. These are not documented because they need generalising before they're more useful to all.

Logging options are also unfinished and undocumented for now.

Development will continue as new requirements (or shortfalls!) surface.
