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
$test = 0; //Will limit all queries to the first 100.


//the mySQL user that runs this script will be called analytics.  This user is creating a
$user = 'analytics';
$password = '';
$host = 'localhost';
$targetDatabase=  'analytics';
$sourceDatabase = '';
$logfile = '/var/log/rstudioconnect.log';

//Here we do all the permission stuff
$showQuery = "SHOW DATABASES";
$dropQuery = "Drop DATABASE $targetDatabase";
$createDatabase = "Create DATABASE $targetDatabase";
$searchUser = "SELECT User FROM mysql.db WHERE Db = '$targetDatabase' AND User = 'rstudio'";;
$createUser = "CREATE USER 'rstudio'@'localhost' IDENTIFIED BY ''; ";
$grantUser = "GRANT SELECT ON $targetDatabase.* TO 'rstudio'@'localhost'; ";
$flush = "Flush Privileges;";

$tableNames = [ 'form_vitals', 'form_encounter', 'form_observation', 'form_questionnaire_assessments', 'questionnaire_response'];

//create a connection as root to the instance of mySQL
$conn = new mysqli($host, $user, $password);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
    file_put_contents($logfile, date('Y-m-d h:i:s') . " " . $conn->connect_error . PHP_EOL, FILE_APPEND);
} else {
    echo date('Y-m-d h:i:s') . "\nConnection to mySQL server successful as user: $user \nconnected to database: $targetDatabase  \n";
    file_put_contents($logfile, date('Y-m-d h:i:s') . "Connection to mySQL server successful as user: 
    $user \nsource data:$sourceDatabase\n target data: $targetDatabase " . PHP_EOL, FILE_APPEND);
}

//allow invalid dates
$result = $conn->query("SET SESSION sql_mode = 'ALLOW_INVALID_DATES';");
//Here we place the queries
$createPatientDataTable = "CREATE TABLE patient_data (
                pid INT,
                dob DATE
            )  ";

//get the pid, dob from patient_data.



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
    file_put_contents($logfile, date('Y-m-d h:i:s') . " User 'rstudio' already exists. Proceeding...." . " ". PHP_EOL, FILE_APPEND);

} else {
    // Create the user
    $result = $conn->query($createUser);
    echo "User 'rstudio' created successfully. \n";
    file_put_contents($logfile, date('Y-m-d h:i:s') . " User 'rstudio' created successfully." . " ". PHP_EOL, FILE_APPEND);
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
//set permissions for rstudio client to only have read-only permissions of the analytic database
$result = $conn -> query("Use $targetDatabase"); //Logged in as

//create the table using the query
if ($conn->query($createPatientDataTable) === TRUE) {
    echo "\nTable 'patient_data' created successfully. \n";

} else {
    die( "Error creating table: " . $conn->error . "\n\n");
}

//patient data
$tempDir = "/var/lib/mysql-files/";
$tempTable = "patient_data";
if (file_exists($tempDir.$tempTable)) {
    // Delete the file
    if (!unlink($tempDir.$tempTable)) {
        die("Error deleting the existing file: $tempDir.$tempTable");
    } else{
        echo "deleted $tempDir.$tempTable \n ";
    }
}

$query = "SELECT pid, dob INTO OUTFILE '$tempDir$tempTable' 
          FIELDS TERMINATED BY ',' 
          OPTIONALLY ENCLOSED BY '\"' 
          LINES TERMINATED BY '\n' 
          FROM $sourceDatabase.patient_data where dob not like '%0000%'";
if($test){
    $query .= "  limit 10";
}
$exportResult = $conn->query($query);
if (!$exportResult) {
    die('Error exporting data from the source table to $tempDir: ' . $conn->error . "\n\n");
    file_put_contents($logfile, date('Y-m-d h:i:s') . ' Error exporting data from the source table: ' . $conn->error . " ". PHP_EOL, FILE_APPEND);
}

$loadquery = "LOAD DATA INFILE '$tempDir$tempTable' 
          INTO TABLE `$targetDatabase`.`patient_data` 
          FIELDS TERMINATED BY ',' 
          OPTIONALLY ENCLOSED BY '\"' 
          LINES TERMINATED BY '\n'";

$loadResult = $conn->query($loadquery);
if (!$loadResult) {
    echo('Error loading data into the destination table: ' .  $conn->error . "\n\n");
} else{
    echo("sucessfully leaded data into the destination table");
}



foreach($tableNames as $tableName){
    $query = "Create table  $targetDatabase.$tableName( Select * from $sourceDatabase.$tableName ";
        if ($test) {
         $query .= " limit 10";
        }
        $query .= " );";

    $exportResult = $conn->query($query);
    if (!$exportResult) {
        echo("Error exporting data from the source table to $sourceDatabase.$tableName: " . $conn->error . "\n\n");
        file_put_contents($logfile, date('Y-m-d h:i:s') . ' Error exporting data from the source table: ' . $conn->error . " ". PHP_EOL, FILE_APPEND);
    } else {

        echo("Success importing data from the source table to : $sourceDatabase.$tableName: " . $conn->error . "\n\n");
        file_put_contents($logfile, date('Y-m-d h:i:s') . ' Success exporting data from the source table: ' . $conn->error . " ". PHP_EOL, FILE_APPEND);

    }
}



$conn->close();
echo "Connection to $targetDatabase successfully closed \n\n";


?>