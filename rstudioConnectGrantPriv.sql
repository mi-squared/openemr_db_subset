CREATE USER IF NOT EXISTS 'analytics'@'localhost' IDENTIFIED BY 'mi2AnalyticUser';
GRANT SELECT ON peds_700.* TO 'analytics'@'localhost';
GRANT ALL PRIVILEGES ON `analytics`.* TO 'analytics'@'localhost';
GRANT SELECT ON analytics.* TO 'rstudio'@'localhost';
GRANT CREATE USER ON *.* TO 'analytics'@'localhost';
GRANT ALL PRIVILEGES ON analytics.* TO 'analytics'@'localhost';
GRANT ALL PRIVILEGES ON `analytics\_%`.* TO 'analytics'@'localhost';
GRANT GRANT OPTION, CREATE USER ON *.* TO 'analytics'@'localhost';
GRANT SELECT ON mysql.db TO 'analytics'@'localhost';
GRANT RELOAD ON *.* TO 'analytics'@'localhost';
FLUSH PRIVILEGES;