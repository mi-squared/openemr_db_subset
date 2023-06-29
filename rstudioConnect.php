<?php
/**
 *  We are writing a script as root that will create a table called analytics if it does not exist.
 *
 *  The table analytics will initially only contain a pid and a dob from patient_data, we will call this table patient_data
 *      See if database exists, if it does we will drop it.
 *      Once dropped we will create a new database called anayltics
 *      We create a table called patient_data that will contain the following columns, pid and dob
 *
 *
 *  The database called analytics will only be accessible by a user called rstudio.  sql user rstudio will only have read access
 *  to the analytics table
 *
 *  After the database and tables are created, we check for the user named 'rstudio'.  If the user does not exist, create it.
 *  Once created we make sure that 'rstudio' only has read access to the anayltics database

CREATE USER 'analytics'@'localhost' IDENTIFIED BY 'mi2AnalyticUser';
-- Grant read-only access to openemr database
GRANT SELECT ON openemr.* TO 'analytics'@'localhost';
GRANT RELOAD ON *.* TO 'analytics'@'localhost';

Create database analytics;
-- Grant full privileges on analytics databases
GRANT ALL PRIVILEGES ON `analytics\_%`.* TO 'analytics'@'localhost';
GRANT SELECT ON mysql.db TO 'analytics'@'localhost';
GRANT CREATE USER ON analytics.* TO 'analytics'@'localhost';
GRANT GRANT OPTION ON analytics.* TO 'analytics'@'localhost';
FLUSH PRIVILEGES;
 *
 */
$test = false; //Will limit all queries to the first 100.


//the mySQL user that runs this script will be called analytics.  This user is creating a
$user = 'analytics';
$password = 'mi2AnalyticUser';
$host = 'localhost';
$targetDatabase=  'analytics';
$sourceDatabase = 'openemr';

//create a connection as root to the instance of mySQL
$conn = new mysqli($host, $user, $password);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    echo date('Y-m-d h:i:s') . "\nConnection to mySQL server successful as user: $user \nconnected to database: $targetDatabase  \n";
}

//Here we do all the permission stuff
$showQuery = "SHOW DATABASES";
$dropQuery = "Drop DATABASE $targetDatabase";
$createDatabase = "Create DATABASE $targetDatabase";
$searchUser = 'SELECT User FROM mysql.db WHERE Db = "$targetDatabase" AND User = "rstudio"';
$createUser = "CREATE USER 'rstudio'@'localhost' IDENTIFIED BY ''; ";
$grantUser = "GRANT SELECT ON $targetDatabase.* TO 'rstudio'@'localhost'; ";
$flush = "Flush Privileges;";

//Here we place the queries
$createPatientDataTable = "CREATE TABLE patient_data (
                pid INT,
                dob DATE
            )  ";

//get the pid, dob from patient_data.
$selectPatientDataQuery = "select pid, dob from $sourceDatabase.patient_data ";
if($test){
    $selectPatientDataQuery .= " limit 100";
}


// Execute the query
$result = $conn->query($showQuery);


// Check if the analytics  database exists in the list, if it exists we drop it to create and install a new one
$databaseExists = false;
while ($row = $result->fetch_assoc()) {
    if ($row['Database'] === $targetDatabase) {
        $databaseExists = true;
        echo "Dropping Database $targetDatabase\n";
        $result = $conn->query($dropQuery);
        break;
    }
}

//Create Analytic Database!
$result = $conn->query($createDatabase);
if($result) {
    echo "Created $targetDatabase successfully \n";

} else {
    die( "Error creating database: " . $conn->error . "\n\n");

}

//check if rstudio user exists, if not create it. Here we assume that analytics is the name of the database.

$result = $conn->query($searchUser);
if ($result->num_rows > 0) {
    echo "User 'rstudio' already exists. Proceeding.... \n";

} else {
    // Create the user
    $result = $conn->query($createUser);
    echo "User 'rstudio' created successfully. \n";
}
//set permissions for rstudio client to only have read-only permissions of the analytic database
$result = $conn -> query("Use $targetDatabase"); //Logged in as

//create the table using the query
if ($conn->query($createPatientDataTable) === TRUE) {
    echo "Table 'patient_data' created successfully. \n";

} else {
    die( "Error creating table: " . $conn->error . "\n\n");
}

//user analytic queries the source database for patient data
$result = $conn->query($selectPatientDataQuery);
if ($result->num_rows > 0) {
    echo("Inserting data into patient_data... \n");
    $stmt = $conn->prepare("INSERT INTO $targetDatabase.patient_data (pid, dob) VALUES (?, ?)");
    $stmt->bind_param("is", $pid, $dob);

    // Process each row of data from OpenEMR and insert into Analytics
    while ($row = $result->fetch_assoc()) {
        $pid = $row["pid"];
        $dob = $row["dob"];
        $stmt->execute();
    }

    // Close the statement
    $stmt->close();

} else {
    die( "Error getting data: " . $conn->error . "\n\n");
}



//grant the user rstudio the ability to have read access to analytics
$result = $conn->query($grantUser);
if ($result) {
    echo "Granted Access to $targetDatabase to $user \n";
}


$result = $conn->query($flush);
if ($result) {
    echo "Flushed.  Permissions should work \n";
}

//handle the vitals!

$tableNames = [ 'form_vitals', 'form_encounter', 'form_observation', 'form_questionnaire_assessments', 'questionnaire_response'];
foreach($tableNames as $tableName){
    $result = $conn->query("SHOW COLUMNS FROM $sourceDatabase.$tableName");

    if (!$result) {
        echo("Error retrieving column information: " . $conn->error . "\n\n");
        continue;
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }

    // Construct the CREATE TABLE query
    $createTableQuery = "CREATE TABLE IF NOT EXISTS $tableName (  ";

    foreach ($columns as $column) {
        $columnName = $column['Field'];
        $columnType = $column['Type'];
        $createTableQuery .= "`$columnName` $columnType, ";
    }

    $createTableQuery = rtrim($createTableQuery, ", ");  // Remove the trailing comma and space
    $createTableQuery .= ")";

// Create the table in $targetDatabase
    if (!$conn->query($createTableQuery)) {
        die("Error creating table in $targetDatabase: " . $conn->error);
    } else {
        echo("Created Table $targetDatabase \n");
    }
    $selectQuery = "SELECT * FROM $sourceDatabase.$tableName";
    if($test) {

        $selectQuery .= " Limit 100";
    }
// Execute the select query
    $result = $conn->query($selectQuery);

// Check if the select query was successful
    if ($result) {
        echo "Inserting $tableName \n";
        $insertQuery = "INSERT INTO `$targetDatabase`.`$tableName` VALUES (";
        for ($i = 0; $i < $result->field_count; $i++) {
            $insertQuery .= ($i > 0 ? ", " : "") . "?";
        }
        $insertQuery .= ")";
        $insertStmt = $conn->prepare($insertQuery);

        while ($row = $result->fetch_assoc()) {
            // Bind the values to the prepared statement parameters dynamically
            $params = array_values($row);
            $types = str_repeat("s", count($params));
            $insertStmt->bind_param($types, ...$params);

            // Execute the prepared statement
            $insertResult = $insertStmt->execute();


        }
    }

}



$conn->close();
echo "Connection to $targetDatabase successfully closed \n\n";


?>