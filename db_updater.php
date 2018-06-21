<?php
/**
 * @package     DB updater
 * @subpackage
 *
 * @copyright   A copyright
 * @license     A "Slug" license name e.g. GPL2
 */

require_once dirname(__FILE__) . '/configuration.php';

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding("UTF-8");

class Updater
{

	private $config, $mysqli;

	function __construct()
	{
		ob_start();

		$this->switchOffBuffering();

		$this->config = new JConfig();

		$this->connect_mysql();
	}

	/**
	 * Перейти в режим отключённой буферизации
	 * @since 1.0
	 *
	 * @param boolean $closeSession
	 * Сохранить и закрыть сессию.
	 * Нужно, если скрипт долгоиграющий, и вы не хотите,
	 * тобы, пока он работает, у вас заклинивало весь остальной сайт.
	 */
	private function switchOffBuffering($closeSession = true)
	{
		if ($closeSession)
		{
			//Сохраним и закроем сессию, если надо.
			session_write_close();
		}
		//Сообщим серверу и браузеру, что кэшировать выдачу не надо.
		header('Cache-Control: no-cache, must-revalidate');
		//Сообщим серверу Nginx, что буферизировать не надо
		header('X-Accel-Buffering: no');
		//Включим автоматический сброс буфера при каждом выводе
		ob_implicit_flush(true);
		//Сбросим все уровни буферов PHP, созданные на данный момент.
		while (ob_get_level() > 0)
		{
			ob_end_flush();
		}
	}

	/*
    * Подключение к mysql
    * */
	private function connect_mysql()
	{
		$this->mysqli = new mysqli($this->config->host, $this->config->user, $this->config->password, $this->config->db);
		$this->mysqli->query("SET NAMES 'utf8';");
		$this->mysqli->query("SET CHARACTER SET 'utf8';");
		$this->mysqli->query("SET SESSION collation_connection = 'utf8_general_ci';");
		$this->mysqli->select_db($this->config->db);

		if ($this->mysqli->connect_errno)
		{
			exit("Не удалось подключиться к MySQL: " . $this->mysqli->connect_error);
		}
	}

	/*
	 * Возвращает имена таблиц базы данных
    * */
	public function returnTablesNames()
	{
		$tables_names = null;

		$query = "SHOW TABLES FROM " . $this->config->db . ";";

		if ($result = $this->mysqli->query($query))
		{
			/* выборка данных и помещение их в массив */
			$i = 0;
			while ($row = $result->fetch_row())
			{
				$tables_names[$i++] = $row[0];
			}

			/* очищаем результирующий набор */
			$result->close();
		}
		else exit('Ошибка во время выполнения запроса \'<strong>' . $query . '\': ' . mysql_error() . '<br /><br />');

		return $tables_names;
	}

	/*
    * Принимает масств имен таблиц
    * и производит их удаление
    * */
	public function deleteTables()
	{
		$tables_names = $this->returnTablesNames();
		if (!empty($tables_names))
		{
			foreach ($tables_names as $table_name)
			{
				$query = "DROP TABLE " . $table_name . ";";
				if ($this->mysqli->query($query))
				{
					echo "Таблица $table_name удалена<br/>";
					ob_flush();
					flush();
				}
				else
				{
					exit('Ошибка во время выполнения запроса \'<strong>' . $query . '\': ' . $this->mysqli->error . '<br /><br />');
				}
			}
			echo 'Очистка базы данных завершена';
		}
		else
		{
			echo "Пустая база данных <br/>";
		}
	}

	/*
    * Функция импорта файла в базу данных
	* Принимает имя файла для импорта
    * */
	public function beginImport($filename)
	{
		ob_flush();
		flush();

		$maxRuntime = 8; // less then your max script execution limit


		$deadline         = time() + $maxRuntime;
		$progressFilename = $filename . '_filepointer'; // файл с указателем на выполненый последним запрос
		$errorFilename    = $filename . '_error'; // файл с текстом ошибки

		($fp = gzopen($filename, 'r')) OR die('Ошибка при открытии файла:' . $filename);

		// проверка на ошибки во время предыдущего импорта
		if (file_exists($errorFilename))
		{
			die('<pre> Предыдущая ошибка: ' . file_get_contents($errorFilename) . '</pre>');
		}

		// activate automatic reload in browser
		//echo '<html><head> <meta http-equiv="refresh" content="' . ($maxRuntime + 2) . '"><pre>';

		// переход в позицию из файла - указателя почледнего запроса
		if (file_exists($progressFilename))
		{
			$filePosition = file_get_contents($progressFilename);
			fseek($fp, $filePosition);
		}

		$queryCount = 0;
		$query      = '';
		while ($deadline > time() AND ($line = fgets($fp, 1024000)))
		{
			if (substr($line, 0, 2) == '--' OR trim($line) == '')
			{
				continue;
			}

			$query .= $line;
			if (substr(trim($query), -1) == ';')
			{
				if (!$this->mysqli->query($query))
				{
					$error = 'Ошибка во время выполнения запроса \'<strong>' . $query . '\': ' . $this->mysqli->error;
					file_put_contents($errorFilename, $error . "\n");
					echo '<pre>' . file_get_contents($errorFilename) . '</pre>';
					exit;
				}
				$query = '';
				file_put_contents($progressFilename, ftell($fp)); // сохраняем текущую позицию
				$queryCount++;
			}
		}

		if (feof($fp))
		{
			echo 'Импорт успешно завершен';
		}
		else
		{
			echo ftell($fp) . '/' . filesize($filename) . ' ' . (round(ftell($fp) / filesize($filename), 2) * 100) . '%' . "\n";
			echo $queryCount . ' запросов выполнено! Повторно запустите процедуру импорта!';
		}
	}

	/*
	 * Функция экспорта базы данных
	 * Принимает имя файла для экспорта
	 * */
	public function beginExport($filename, $gz_compression)
	{
		$target_tables = null;
		$content       = "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";";
		$content       .= "\n\n\n-- ---------------------------------------------------";
		$content       .= "\n-- ------База данных: `" . $this->config->db . "`";
		$content       .= "\n-- ---------------------------------------------------";
		$content       .= "\n-- ---------------------------------------------------";

		$backup_name = $filename;

		$target_tables = $this->returnTablesNames();

		foreach ($target_tables as $table)
		{
			$result        = $this->mysqli->query('SELECT * FROM ' . $table);
			$fields_amount = $result->field_count;
			$rows_num      = $this->mysqli->affected_rows;
			$res           = $this->mysqli->query('SHOW CREATE TABLE ' . $table);
			$TableMLine    = $res->fetch_row();
			$content       .= "\n\n--\n-- Структура таблицы - " . $table . "\n--";
			$content       .= "\n\nDROP TABLE IF EXISTS " . $table . ";" . "\n\n" . $TableMLine[1] . ";\n\n";

			for ($i = 0, $st_counter = 0; $i < $fields_amount; $i++, $st_counter = 0)
			{
				while ($row = $result->fetch_row())
				{ //when started (and every after 100 command cycle):
					if ($st_counter % 100 == 0 || $st_counter == 0)
					{
						$content .= "\nINSERT INTO " . $table . " VALUES";
					}
					$content .= "\n(";
					for ($j = 0; $j < $fields_amount; $j++)
					{
						$row[$j] = str_replace("\n", "\\n", addslashes($row[$j]));
						if (isset($row[$j]))
						{
							$content .= '"' . $row[$j] . '"';
						}
						else
						{
							$content .= '""';
						}
						if ($j < ($fields_amount - 1))
						{
							$content .= ',';
						}
					}
					$content .= ")";
					//every after 100 command cycle [or at last line] ....p.s. but should be inserted 1 cycle eariler
					if ((($st_counter + 1) % 100 == 0 && $st_counter != 0) || $st_counter + 1 == $rows_num)
					{
						$content .= ";";
					}
					else
					{
						$content .= ",";
					}
					$st_counter = $st_counter + 1;
				}
			}
			$content .= "\n\n\n";
		}
		$backup_name = $backup_name ? $backup_name : $this->config->db . ".sql";
		header('Content-Type: application/octet-stream');
		header("Content-Transfer-Encoding: Binary");
		header("Content-disposition: attachment; filename=\"" . $backup_name . "\"");

		if ($gz_compression)
		{
			echo gzencode($content);
			exit;
		}
		else
		{
			echo $content;
			exit;
		}

	}

	public function deleteFiles($dump_file)
	{
		if ($dump_file)
		{
			if (!unlink($dump_file . '_filepointer')) echo "Ошибка во время удаления " . $dump_file . '_filepointer<br/>';
			if (!unlink($dump_file)) echo "Ошибка во время удаления " . $dump_file . '<br/>';
		}
		if (!unlink(dirname(__FILE__) . '/db_updater.php')) echo "Ошибка во время удаления " . dirname(__FILE__) . '/db_updater.php<br/>';
		echo "Удаление завершено";
	}

	function __destruct()
	{
		ob_end_flush();
		$this->mysqli->close();
	}
}

$updater = new Updater();

$command        = $_POST['command'];
$filename       = $_POST['filename'];
$dump_file      = $_POST['dump_file'];
$gz_compression = 0;

if (!empty($_POST['gz_compression']))
{
	$filename       = $_POST['filename'] . ".sql.gz";
	$gz_compression = 1;
}
else
{
	$filename = $_POST['filename'] . ".sql";
}

switch ($command)
{
	case 'export':
		$updater->beginExport($filename, $gz_compression);
		break;
	case 'import':
		$updater->beginImport($filename);
		break;
    case 'clear_db':
        $updater->deleteTables();
        break;
	case 'delete_files'    :
		$updater->deleteFiles($dump_file);
		break;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Скрипт для работы с БД Joomla</title>
</head>
<body>
<form id="controlForm" method="post">
    <label>Имя файла
        <input id="filename" type="text" name="filename"/>
    </label>
    <label><input id="gz_compression" type="checkbox" name="gz_compression" checked/>Компрессия gz</label>
    <input id="export" type="button" name="export" value="Экспорт"/>
    <input id="import" type="button" name="import" value="Импорт"/>
    <input id="clear_db" type="button" name="clear_db" value="Очистить базу данных"/>
    <input id="delete_files" type="button" name="delete_files" value="Удалить файлы скрипта"/>
    <input id="command" type="hidden" name="command" value="0"/>
    <input id="dump_file" type="hidden" name="dump_file" value="0"/>
</form>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        let export_button = document.getElementById('export'),
            import_button = document.getElementById('import'),
            clearDb_button = document.getElementById('clear_db'),
            delete_button = document.getElementById('delete_files'),
            form = document.getElementById('controlForm'),
            command_input = document.getElementById("command");

        export_button.addEventListener("click", begin_export);
        import_button.addEventListener("click", begin_import);
        clearDb_button.addEventListener("click", begin_clearing_db);
        delete_button.addEventListener("click", begin_deleting_files);

        // возвращает cookie с именем name, если есть, если нет, то undefined
        function getCookie(name) {
            let matches = document.cookie.match(new RegExp(
                "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
            ));
            return matches ? decodeURIComponent(matches[1]) : undefined;
        }

        function setCookie(name, value, options) {
            options = options || {};

            let expires = options.expires;

            if (typeof expires === "number" && expires) {
                let d = new Date();
                d.setTime(d.getTime() + expires * 1000);
                expires = options.expires = d;
            }
            if (expires && expires.toUTCString) {
                options.expires = expires.toUTCString();
            }

            value = encodeURIComponent(value);

            let updatedCookie = name + "=" + value;

            for (let propName in options) {
                updatedCookie += "; " + propName;
                let propValue = options[propName];
                if (propValue !== true) {
                    updatedCookie += "=" + propValue;
                }
            }

            document.cookie = updatedCookie;
        }

        function deleteCookie(name) {
            setCookie(name, "", {
                expires: -1
            })
        }

        function checkFilename() {
            let filename = document.getElementById("filename").value,
                gz_compression_input = document.getElementById("gz_compression");

            if (filename === '') {
                alert("Введите имя файла");
                return 0;
            }

            if (gz_compression_input.checked) {
                filename = filename + ".sql.gz";
            }
            else {
                filename = filename + ".sql";
            }

            return filename;
        }

        function begin_export() {
            let filename = checkFilename();
            if (!filename) return 0;

            let isConfirm = confirm("Начать экспорт базы данных в файл " + filename + "?");
            if (!isConfirm) return 0;

            command_input.value = "export";
            form.submit();
        }

        function begin_import() {
            let filename = checkFilename();
            if (!filename) return 0;

            let isConfirm = confirm("Начать импорт базы данных из файла " + filename + "?");
            if (!isConfirm) return 0;

            setCookie('updater_dump_filename', filename);

            command_input.value = "import";
            form.submit();
        }

        function begin_clearing_db() {
            let isConfirm = confirm("Начать очистку базы данных?");
            if (!isConfirm) return 0;

            command_input.value = "clear_db";
            form.submit();
        }

        function begin_deleting_files() {
            let dump_file = getCookie('updater_dump_filename'),
                isConfirm;

            if (dump_file !== undefined) {
                isConfirm = confirm("Будут удалены файлы скрипта и дамп базы " + dump_file + ". Продолжить?")
            }
            else {
                isConfirm = confirm("Будут удалены файлы скрипта. Продолжить?")
            }
            if (!isConfirm) return 0;

            deleteCookie('updater_dump_filename');

            command_input.value = "delete_files";
            if (dump_file !== undefined) document.getElementById('dump_file').value = dump_file;
            form.submit();
        }
    });
</script>

</body>
</html>