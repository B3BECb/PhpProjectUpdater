<?php
/**
* 
*/
class Log
{
	static function write($mess="", $name="info_error")
	{
        if(strlen(trim($mess)) < 2)
        {
			return fasle;
        }
	    if(preg_match("/^([_a-z0-9A-Z]+)$/i", $name, $matches))
	    {
			$file_path='logs/'.$name.'.txt';
			$text = date("d.m.Y (H:i:s)")." - ".htmlspecialchars($mess)."\r\n";
			$handle = fopen($file_path, "a+");
			@flock ($handle, LOCK_EX);
			fwrite ($handle, $text);
			//fwrite ($handle, "==============================================================\r\n\r\n");
			@flock ($handle, LOCK_UN);
			fclose($handle);
			return true;
	    }
	        else {return false;}
	}
}

/**
* Класс, содержащий описание изменений в базе  данных и в директории проекта
*/
class UpdateInformation
{
	public 	$BdRequests;
	public 	$FilesPaths;
	public 	$projectName;
	public 	$projectDbName;
	public 	$projectDbUser;
	public 	$projectDbPassword;
	public 	$incomingVersion;	

	function __construct($xml)
	{
		$xmlFile = simplexml_load_file($xml);		

		if (isset($xmlFile->database->request))
		{
			$BdRequests = array();
			$i = 0;
			foreach ($xmlFile->database->request as $request) 
			{
				$this->BdRequests[$i] = $request;
				$i++;
			}			
		} 	

		if (isset($xmlFile->paths->path))
		{
			$FilesPaths = array();
			$i = 0;
			foreach ($xmlFile->paths->path as $path) 
			{
				$this->FilesPaths[$i] = $path;
				$i++;
			}
		}

		$this->incomingVersion 		= $xmlFile->versionInfo->incomingVersion; 
		$this->projectName 			= $xmlFile->projectInfo->Name;	
		$this->projectDbName 		= $xmlFile->projectInfo->DatabaseName;
		$this->projectDbUser 		= $xmlFile->projectInfo->DatabaseUser;
		$this->projectDbPassword 	= $xmlFile->projectInfo->DatabasePassword;
	}

	function UpdateInfo()
	{
		echo 	"Версия обновления: ".$this->incomingVersion."<hr>";

		$countRequests = count($this->BdRequests);

		echo "Обновление базы данных: ".(($countRequests)?("Да"):("Нет"))."<br>Запросов: ".$countRequests;

		$countFiles = count($this->FilesPaths);

		echo "<br>Обновление файлов: ".(($countFiles)?("Да"):("Нет"))."<br>Файлов на обновление: ".$countFiles;
	}

	function CreateBdInfo()
	{
		echo 	"Версия: ".$this->incomingVersion."<hr>";

		$countRequests = count($this->BdRequests);

		echo "<br>Запросов: ".$countRequests;
	}
}

/**
* Класс управления ФС
*/
class FilseSystemManipulator 
{
	private $projectName;
	private $filesArray;
	private $version;

	function __construct($projectName, $filesArray, $version)
	{
		$this->projectName = $projectName;
		$this->filesArray = $filesArray;
		$this->version = $version;
	}

	function createBackup()
	{	
		$backupName = "Backups/$this->projectName-v$this->version";
		if (!is_dir($backupName) && !mkdir($backupName))
			throw new Exception("Ошибка создания директории резервной копии. 
								 Возможно директория с таким именем уже существует.
								 Отмена создания резервной копии.", 1);

		foreach ($this->filesArray as $file) 
		{
			$pathParth = pathinfo($file);
			$fileName = $pathParth['basename'];
			$filePath = $pathParth['dirname'];

			if ($backupName.$filePath != '.')
			{
				$filePath = $backupName.$filePath;
				
				$explodedPath = explode('/', $filePath);  

				$currentDirectory=array(); 
				foreach($explodedPath as $key => $val){  
				    $currentDirectory[]=$val;

				    if (!is_dir(implode('/',$currentDirectory))) 
				    	mkdir(implode('/',$currentDirectory)."/"); 
				} 
				
			}

			if (!@copy("../$this->projectName/$file", "$backupName/$file"))
			{	
				$this->removeDirectory($backupName);		
				throw new Exception("Ошибка создания резервной копии $this->projectName$file. 
								 Невозможно скопировать исходные файлы.
								 Отмена создания резервной копии.", 3);
			}
			else
				echo "Создана резервная копия: $this->projectName$file<br>";
		}		
	}

	function removeDirectory($dir)
	{
	    if ($objs = glob($dir."/*")) {
	       foreach($objs as $obj) {
	         is_dir($obj) ? $this->removeDirectory($obj) : unlink($obj);
	       }
	    }
	    rmdir($dir);
  	}

	function updateFiles()
	{
		foreach ($this->filesArray as $file) 
		{
			if (!@copy("Data/$file", "../$this->projectName/$file"))
			{
				$this->rollback("Ошибка обновления $this->projectName$file.
								 Откат изменений.<br>");
				throw new Exception("");
			}
			else
				echo "Обновлён файл: $this->projectName$file<br>";
		}
	}

	function rollback($message)
	{
		echo "<font color='red'>".$message."</font>";
		$backupName = "Backups/$this->projectName-v$this->version";
		foreach ($this->filesArray as $file) 
		{
			if (!@copy("$backupName/$file", "../$this->projectName/$file"))
			{
				throw new Exception("Ошибка отката файла $this->projectName$file.", 2);
			}
			else
				echo "<font color='cornflowerblue'>Восстановлен файл: $this->projectName$file</font><br>";
		}
	}
}

/**
* Класс управления базой данных
*/
class DatabaseUpdater
{
	private $Name;
	private $Password;
	private $User;
	private $Requests;
	
	function __construct($BdName, $BdUser, $BdPassword, $BdRequests)
	{
		$this->Name 		= $BdName;
		$this->User 		= $BdUser;
		$this->Password 	= $BdPassword;
		$this->Requests 	= $BdRequests;
	}

	function updateBd()
	{
		$mysqli = new mysqli("localhost", $this->User, $this->Password, $this->Name);
		if ($mysqli->connect_errno) 
		{
    		throw new Exception	("Не удалось подключиться к MySQL: (" . 
    				$mysqli->connect_errno . ") " . $mysqli->connect_error, 1);
		}

		$mysqli->autocommit(false);
		foreach ($this->Requests as $request) 
		{
			if (!$mysqli->query($request))
			{
				$mysqli->rollback();
	    		throw new Exception	("Не удалось выполнить запрос ($request)". $mysqli->error, 1);
			}
			else
				echo "запрос $request выполнен<br>";
		}
		$mysqli->autocommit(true);
	}
}

/**
* Главный класс программы
*/
class Project
{
	public function Update()
	{
		$XMLInfo = new UpdateInformation("Update.xml");
		$XMLInfo->UpdateInfo();	

		try
		{
			if (count($XMLInfo->FilesPaths))
			{
				$FS = new FilseSystemManipulator($XMLInfo->projectName, $XMLInfo->FilesPaths, $XMLInfo->incomingVersion);
				echo "<hr><h2>Создание резервной копии</h2>";
				$FS->createBackup();
				echo "<hr><h2>Обновление файлов</h2>";
				$FS->updateFiles();
			}

			if (count($XMLInfo->BdRequests))
			{
				$DBupdater = new DatabaseUpdater($XMLInfo->projectDbName, $XMLInfo->projectDbUser, $XMLInfo->projectDbPassword, $XMLInfo->BdRequests);
				echo "<hr><h2>Обновление базы данных</h2>";
				$DBupdater->updateBd();
				
			}
		}
		catch (Exception $e)
		{
			echo "<font color='red'>".$e->getMessage()."</font><br>";			
			if (($e->getCode() != 3) && isset($FS)) $FS->rollback('');
			Log::write($e->__toString());
			echo "<hr><center><font color='red'><h1>Ошибка при обновлении</h1></font></center>";
			return;
		}

		echo "<hr><center><font color='green'><h1>Обновление проведено успешно</h1></font></center>";
	}

	public function CreateDB()
	{
		$XMLInfo = new UpdateInformation("CreateDB.xml");
		$XMLInfo->CreateBdInfo();

		try
		{
			if (count($XMLInfo->BdRequests))
			{
				$DBupdater = new DatabaseUpdater($XMLInfo->projectDbName, $XMLInfo->projectDbUser, $XMLInfo->projectDbPassword, $XMLInfo->BdRequests);
				echo "<hr><h2>Создание базы данных</h2>";
				$DBupdater->updateBd();

			}
		}
		catch (Exception $e)
		{
			echo "<font color='red'>".$e->getMessage()."</font><br>";
			if (($e->getCode() != 3) && isset($FS)) $FS->rollback('');
			Log::write($e->__toString());
			echo "<hr><center><font color='red'><h1>Ошибка при Создании БД</h1></font></center>";
			return;
		}

		echo "<hr><center><font color='green'><h1>Создание проведено успешно</h1></font></center>";
	}
}

if ($_GET['u'])
{
	$project = new Project();
	$project->Update();
}
elseif($_GET['c'])
{
	$project = new Project();
	$project->CreateDB();
}
else
	echo 
	"
		<center>
			<button onClick=\"location.href='?c=1'\">Создать БД</button>
			<button onClick=\"location.href='?u=1'\">Обновить</button>
		</center>
	";


?>