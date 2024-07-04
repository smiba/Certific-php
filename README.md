# Certific-PHP
Certific-PHP is a set of scripts to run a CT Log search service, these scripts allow for scraping CT logs and keeping these scrapes up to date. 

This is a project that was originally started in 2019 and stranded in 2020. We've decided to release the code instead of letting it go to waste, it's very rudimentary and could use some work. 

## Setup 

1. Setup MySQL Database and load the table structure in dump.sql
2. Configure the database credentials in every file (no global settings at the moment, sorry!)
3. Manually create a row in "logs" for every CT Log you wish to use in this software    
   For example: ```INSERT INTO logs(`log`) VALUES('ct.googleapis.com/logs/us1/argon2024');```
4. Generate jobs that can be picked up by workers:     
   ```php jobs/generate_jobs.php ct.googleapis.com/logs/us1/argon2024```     
   This will generate jobs for the entire size of the log.
        
   To limit the size during testing, you can define a start and an end like this:    
   ```php jobs/generate_jobs.php ct.googleapis.com/logs/us1/argon2024 0 1000```
5. Start one or more workers, you will want to run these headless or in a screen.     
   ```php jobs/worker.php```

Workers can be left open at all times, they will run idle when all jobs are done and will start picking up work as new jobs appear. 

This readme and the whole software as a whole is very much unfinished, although it will totally work. PR's are appriciated. 
