<?php

/*
	For sample usage and documentation, view readme at http://github.com/davidsalazar/rsync
	TODO: 
		Add environment test method to test if directories are writable, ssh and mysql users are valid.
		Add intelligent logging.
*/
	
class Rsync
{
	/**
	 * Will check for existence of this file for a list of files and dirs to exclude.
	 * Directories and files are relative to the source path.
	 * If not prefixed with /. it will match every directory.
	 * For more info, read http://articles.slicehost.com/2007/10/10/rsync-exclude-files-and-folders
	 * 
	 *
	 * @var string
	 */
	public $exclude_file = 'exclude.txt';

	/**
	 * Mysql backup directory relative to the source path.
	 *
	 * @var string
	 */
	public $mysql_dir = '_sql';
	public $mysql_backup_interval = 'daily';
	public $mysql_gzip = FALSE;
	
	// The following intervals must have a minimum of 1 backup.
	public $hourly = 2;
	public $daily = 2;
	public $weekly = 2;
	public $monthly = 2;

	public $rsync_bin = 'rsync';
	public $rsync_switches = '-avz --delete';

	private $source;
	private $dest;
	private $cp_switches;
	private $cmd;
	private $output;
	private $backups = array('hourly' => '-1 hour', 'daily' => '-1 day', 'weekly' => '-7 day', 'monthly' => '-30 day');
	private $mysql_backups = array();
	private $time_limit = 30;

	/**
	 * Instantiate an object by setting the source and destination path.
	 *
	 * @param string $source Ssh url or local absolute path
	 * @param string $dest Local absolute path
	 */
	public function __construct($source, $dest)
	{
		// OSX is gay and doesn't support hard links via copy using the cp command
		$this->cp_switches = PHP_OS == 'Darwin' ? '-pPR' : '-al';

		$this->source = rtrim($source, '/');
		$this->dest = rtrim($dest, '/');

		is_writable($this->dest) or die("Destination directory doesn't exist or isn't writable: $this->dest");

	}
	
	/**
	 * Any public properties can be configured after object is instantiated.
	 *
	 * @param array $options Associate array with index being property name and value being property value.
	 * @return void
	 */
	public function set_options($options = array())
	{
		$public_properties = $this->get_public_properties();

		foreach ($options as $k => $v)
		{
			if (in_array($k, $public_properties))
			{
				$this->$k = $v;
			}
		}
	}

	/**
	 * Begin backup procedure.
	 *
	 * @return string $results Results from backup script are returned as html.
	 */
	public function init()
	{
		set_time_limit(60 * $this->time_limit);

		$this->prep();

		$this->cmd .= "$this->source/ $this->dest/current";
		$rsync_output = $this->exec($this->cmd);

		if (strpos($rsync_output, 'total size is') === FALSE || strpos($rsync_output, 'rsync error') !== FALSE)
			die("<h3>Backup Failed</h3><p>" . nl2br($this->output) . "</p>");

		if ($this->is_empty_dir("$this->dest/current"))
			die("<h3Backup failed</h3><p><i>$this->dest/current</i> is an empty dir.</p><h3>Output</h3><p>" . nl2br($this->output) . "</p>");
		
		$this->process();
		
		return nl2br($this->output);
	}
	
	/**
	 * Add a mysql backup.
	 *
	 * @param string $dbhost 
	 * @param string $dbuser 
	 * @param string $dbpass 
	 * @param array $dbnames (optional, if omitted all databases will be backed up)
	 * @return void
	 */
	public function add_mysql($dbhost, $dbuser, $dbpass, $dbnames = array())
	{
		$this->mysql_backups[] = array('dbhost' => $dbhost, 'dbuser' => $dbuser, 'dbpass' => $dbpass, 'dbnames' => $dbnames);
	}

	private function prep()
	{
		$this->cmd = "$this->rsync_bin $this->rsync_switches ";
		$this->mysql_dir = rtrim($this->mysql_dir, '/');


		$base_path = dirname(__FILE__) . '/';
		if (file_exists($exclude_file = $base_path . $this->exclude_file))
		{
			$this->cmd .= "--exclude-from '$exclude_file' ";
		}

		// If source does not start with a /, we assume it's an ssh url
		if (strpos($this->source, '/') !== 0)
		{
			$this->cmd .= '-e ssh '; 
		}
	}

	private function process()
	{	
		$prev_interval = NULL;

		foreach ($this->backups as $interval => $expiration)
		{
			$prev_interval_count = $prev_interval ? ($this->$prev_interval - 1) : NULL;

			// If the last increment isn't complete, we will wait until it is.
			if ($interval != 'hourly' && !is_dir("$this->dest/$prev_interval.$prev_interval_count"))
			{
				continue;
			}
			
			$interval_count = $this->$interval - 1;

			if ($interval == 'hourly' || !is_dir("$this->dest/$interval.0") || ($this->filemtime("$this->dest/$interval.0") < strtotime($expiration)))
			{
				$this->exec("rm -rf $this->dest/$interval.$interval_count");
		
				for ($i = $interval_count - 1; $i >= 0; $i--)
				{
					$increment = $i + 1;
					if (is_dir("$this->dest/$interval.$i"))
					{
						$this->exec("mv $this->dest/$interval.$i $this->dest/$interval.$increment");
					}
				}

				if ($interval == 'hourly')
				{
					$this->exec("cp $this->cp_switches $this->dest/current $this->dest/$interval.0");
				}
				else
				{
					$this->exec("cp $this->cp_switches $this->dest/$prev_interval.$prev_interval_count $this->dest/$interval.0");
					touch("$this->dest/$interval.0");
				}

				$this->process_mysql($interval);

				
			}

			$prev_interval = $interval;
		}
		
	}

	private function process_mysql($interval)
	{
		if ($this->mysql_backup_interval == $interval)
		{
			@mkdir("$this->dest/$interval.0/$this->mysql_dir");

			if (is_writable("$this->dest/$interval.0/$this->mysql_dir") && count($this->mysql_backups))
			{
				file_put_contents("$this->dest/$interval.0/$this->mysql_dir/.htaccess", 'deny from all');
				foreach ($this->mysql_backups as $mysql_backup)
				{
					if (count($mysql_backup['dbnames']))
					{
						$dbnames = $mysql_backup['dbnames'];
					}
					else
					{
						$dbnames = $this->exec("mysql -h{$mysql_backup['dbhost']} -u{$mysql_backup['dbuser']} -p{$mysql_backup['dbpass']} -e 'show databases' ");
						$dbnames = @explode("\n", trim($dbnames));
						array_shift($dbnames);
					}

					foreach ($dbnames as $dbname)
					{
						$mysqldump = "mysqldump --opt -h{$mysql_backup['dbhost']} -u{$mysql_backup['dbuser']} -p{$mysql_backup['dbpass']} $dbname ";
						if ($this->mysql_gzip)
						{
							$mysqldump .= "| gzip > $this->dest/$interval.0/$this->mysql_dir/$dbname.sql.gz";							
						}
						else
						{
							$mysqldump .= "> $this->dest/$interval.0/$this->mysql_dir/$dbname.sql";
						}
						$this->exec($mysqldump);
					}
				}
			}
		}
	}

	private function is_empty_dir($dir)
	{
     	return count(@scandir($dir)) <= 2;
	}
	
	private function exec($cmd)
	{
		$this->output .= '<h4>' . $cmd . '</h4>';
		$this->output .= '<p>' . ($output = shell_exec("$cmd 2>&1")) . '</p>';
		
		return $output;
	}

	private function filemtime($file)
	{
		return trim(shell_exec(PHP_OS == 'Darwin' ? "stat -f %m $file" : "stat -c %Y $file"));
	}

	private function get_public_properties()
	{
		$this_object = new ReflectionObject($this);
		$properties = $this_object->getProperties();
		
		$public_properties = array();
		foreach ($properties as $property)
		{
			if ($property->isPublic())
			{
				$public_properties[] = $property->getName();
			}
		}
		
		return $public_properties;
	}
}


