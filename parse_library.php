<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Парсинг книги
 */
class Parse_library {
	protected $options = array(
		'file' => '',
		'case' => false,
		'chapter' => 'Глава %d#1-10',
		'chapter_tag_left' => '',
		'chapter_tag_right' => '',
		'string_end' => '<br>',
		'charset' => 'UTF-8',
		'next_string_as_charset' => false,
	);

	/**
	 * Конструктор
	 * @param array
	 */
	public function __construct($options = array()) {
		$this->CI =& get_instance();
		$this->init($options);
	}

	/**
	 * Инициализация данных
	 * @param array
	 */
	public function init($options = array()) {
		foreach($this->options as $key=>$value) {
			if (isset($options[$key])) {
				$this->options[$key] = $options[$key];
			}
		}
	}

	/**
	 * Считывание файла
	 * @param string
	 * @return string
	 */
	private function readFile($file) {
		if (!is_file($file)) {
			return false;
		}

		$content = '';
		$extension = strtolower(substr(strrchr($file, '.'), 1));

		if ($extension == 'docx') {
			$content = $this->readDocx($file);
		} elseif ($extension == 'txt' || $extension == 'html') {
			$content = $this->readTxt($file);
		}

		return $content;
	}

	/**
	 * Считывание docx файлов
	 * @param string
	 * @return mixed (string | bool)
	 */
	private function readDocx($file) {
		$docxZip = new ZipArchive;
		$docxZipRes = $docxZip->open($file);
		if ($docxZipRes === true) {
				$content = $docxZip->getFromName('word/document.xml');
				$content = str_replace('</w:r></w:p></w:tc>', "\n", $content);
				$content = str_replace('</w:r></w:p>', "\r\n", $content);
				$content = str_replace('</w:p>', "\n", $content);
				$content = str_replace('</w:t>', "\t", $content);
				$content = strip_tags($content, "\n\t\r");
				$docxZip->close($file);
		} else {
			return false;
		}
		return $content;
	}

	/**
	 * Считавание txt и html файлов
	 */
	private function readTxt($file) {
		$text = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		return $text;
	}

	/**
	 * Обработка псевдо-регулярного описания главы в регулярное
	 * @param string
	 * @return string
	 */
	private function parseChapter($str = '') {
		$str = $this->encoding($str);

		// экранируем спец символы
		$preg_symbols = array('.', "\\", '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':', '-');
		foreach($preg_symbols as $s) {
			// эти символы надо обрабатывать по-другому
			if ($s != '-' && $s != '\\') {
				$str = str_replace($s, preg_quote($s), $str);
			}
		}

		$regular = $str;

		preg_match_all('/(%s|#\d*(\-\d*)?|%d)/im', $str, $matches);

		// кроме 0-го индекса не смотрим
		foreach($matches[0] as $m) {
			$reg = null;

			if (stripos($m, '%s') !== false) {
				$reg = '[а-яА-Яa-zA-Z]';
			} elseif (stripos($m, '%d') !== false) {
				$reg = '[0-9]';
			} elseif (stripos($m, '#') !== false) {
				$tmp_arr = explode('-', $m);
				$tmp_arr[0] = str_ireplace('#', '', $tmp_arr[0]);

				$num_str_old = '{';
				$num_str = '{';
				if (is_numeric($tmp_arr[0])) {
					$num_str .= $tmp_arr[0];
				}
				// если 2 число после "-", то ставим как границу, иначе ставим "," без правой границы
				if (!empty($tmp_arr[1]) && is_numeric($tmp_arr[1])) {
					$num_str .= ','.$tmp_arr[1];
				} elseif (is_numeric($tmp_arr[0])) {
					$num_str .= ',';
				}

				// если нет чисел, то ставим "0 и более"
				if ($num_str_old == $num_str) {
					$num_str .= '0,';
				}

				$num_str .= '}';

				$reg = $num_str;
			}

			if ($reg !== null) {
				$regular = preg_replace('/'.$m.'/i', $reg, $regular, 1);
			}
		}

		return $regular;
	}

	/**
	 * Кодировка строки в заданную кодировку
	 * @param string
	 * @return string
	 */
	private function encoding($str = '') {
		$encod = mb_detect_encoding($str, array('UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5'), TRUE);
		if ($encod && strtoupper($encod) != strtoupper($this->options['charset'])) {
			$out = iconv($encod, $this->options['charset'], $str);
		} else {
			$out = $str;
		}

		return $out;
	}

	/**
	 * Разбивает текстна строки на основе символов табуляции, перевода строки, возврата коретки
	 * @param string
	 * @return array
	 */
	private function parseTextToString($text) {
		$str_arr = preg_split('/((\n\r)|(\r\n)|\n|\t|\r|\m)/m', $text, PREG_SPLIT_NO_EMPTY);
		foreach($str_arr as $key => $s) {
			$s = $this->encoding(trim($s));
			if (!empty($s)) {
				$str_arr[$key] = $s . $this->options['string_end'];
			} else {
				unset($str_arr[$key]);
			}
		}

		return $str_arr;
	}

	/**
	 * Оформление главы
	 * @param string
	 * @return string
	 */
	private function typographyChapter($str) {
		$str = str_replace($this->options['string_end'], '', $str);
		return $this->options['chapter_tag_left'].$str.$this->options['chapter_tag_right'].$this->options['string_end'];
	}

	/**
	 * Запуск парсера
	 * @return array
	 */
	public function parse() {
		$case = (!empty($this->options['case'])) ? 'i' : '';
		$chapter = $this->parseChapter($this->options['chapter']);

		$content = $this->readFile($this->options['file']);
		$text = (is_array($content)) ? $content : $this->parseTextToString($content);

		// сборщик данных
		$chapters = array();
		$k = 0;
		foreach($text as $s) {
			if (!isset($chapters[$k]['strings'])) {
				$chapters[$k]['strings'] = array();
			}
			if (!isset($chapters[$k]['chapter'])) {
				$chapters[$k]['chapter'] = '';
			}

			if (!preg_match_all('/'.$chapter.'/'.$case.'m', $s, $matches)) {
				$chapters[$k]['strings'] = array_merge_recursive($chapters[$k]['strings'], $this->parseTextToString($s));
			} else {
				// обход всех найденных совпадений с главами
				foreach($matches as $match) {
					$count_match = count($match);
					// обход всех совпадений с главами в строке
					for($i=0; $i<$count_match; $i++) {
						$k++;

						// вырезаем текущую главу
						$str_not_chap = explode($match[$i], $s, 2);
						// если нет 1-го индекса, значит берем 0 (строка без названия главы уже)
						$str_now_chapt = (isset($str_not_chap[1])) ? $str_not_chap[1] : $str_not_chap[0];

						// если в одной строке есть несколько глав
						if (isset($match[$i+1])) {
							// делим строку на часть текущей главы, и часть следующей
							$tmp_str = explode($match[$i+1], $str_now_chapt, 2);

							// текущую строку для следующей итерации меняем. Если нет 1-го индекса, значит берем 0 (строка без названия главы уже)
							$s = (isset($tmp_str[1])) ? $tmp_str[1] : $tmp_str[0];

							$str = $tmp_str[0];
						} else {
							$str = $str_now_chapt;
						}

						if (!isset($chapters[$k]['strings'])) {
							$chapters[$k]['strings'] = array();
						}

						$str_arr = $this->parseTextToString($str);

						// берем следующую строку как часть главы
						$next_string_as_charset = '';
						if ($this->options['next_string_as_charset'] && !empty($str_arr[0])) {
							$next_string_as_charset = $str_arr[0];
							unset($str_arr[0]);
						}

						$chapters[$k]['strings'] = array_merge_recursive($chapters[$k]['strings'], $str_arr);
						$chapters[$k]['chapter'] = $this->typographyChapter($this->encoding($match[$i].' '.$next_string_as_charset));
					}

				}
			}

		}

		return $chapters;
	}

}
