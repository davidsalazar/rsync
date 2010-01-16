What is this
===============================================
- Inspired by rsnapshot (http://rsnapshot.org/), this is a php class that does both local and offsite incremental backups using rsync while using hard links to preserve disk space.
- It's far simpler then rsnapshot to setup and adds mysql backup support as well.
- A sample backup setup will look something like this. 
<pre>
	daily.0
	daily.1
	hourly.0
	hourly.1
	monthly.0
	monthly.1
	weekly.1
	weekly.1
</pre>

Requirements
===============================================
1.  rsync, mysql, and mysqldump executables must be installed locally.
2.  PHP has to be run as a user with writable permission to the destination directory.
3.  If using ssh url, paswordless ssh must be setup by putting your public ssh key onto the remote server's authorized keys.
4.  If backing up mysql remotely, you must give the local machine mysql access.

Sample Usage
===============================================

<pre>
// There are only 2 public methods, add_mysql() and init().
// See Rsync Class for method documentation.

$rsync = new Rsync('user@mydomain.com:/home/user/mydomain.com', '/home/dave/backups');
$rsync->add_mysql('mysql.mydomain.com', 'db_user', 'db_pass');
$result = $rsync->init();
</pre>

Installation
===============================================
1.  After you setup your first backup script, test and run the script from the cmd line.
2.  After initial backup is complete and you see no errors, you will need to run this script hourly.