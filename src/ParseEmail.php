<?php
	namespace ServiceTo;

	class ParseEmail {
		/**
		 * Parse Email into parts and return jsonapi-style array
		 *
		 * @param  string  $email         body of email as piped in by postfix.
		 * @param  boolean $is_section    are we parsing an email or a section within the email
		 * @param  array   $parsedheaders array of headers to accompany the section we're working on
		 * @return array
		 */
		public function parse($email, $is_section = false, $parsedheaders = []) {
			$recordsection = 0;
			$section = 0;
			$headers = 1;
			$lastheader = "";
			$message = [];
			$message["boundary"] = "";
			$message["messageblocks"] = [];
			$message["messageblocks"][0] = "";
			$message["parts"] = [];

			if (count($parsedheaders) > 0) {
				$headers = 0;
				$message["headers"] = $parsedheaders;
			}

			if (!$is_section) {
				$message["hash"] = sha1($email);
				$message["original"] = $email;
			}

			$i = 0;
			$lines = preg_split("/\n/", $email);
			if (is_array($lines)) {
				foreach ($lines as $line) {
					$i++;
					if ($i == 1 && !$is_section) {
						$message["envelope"] = trim($line);
					}
					elseif ($headers == 1) {
						if (trim($line) == "") {
							$headers = 0;
						}
						elseif (trim(substr($line, 0, 1)) == "") {
							// add this to the previous header.
							$message["headers"][$lastheader] .= "\n" . rtrim($line);
						}
						else {
							$lastheader = substr($line, 0, strpos($line, ":"));
							$message["headers"][$lastheader] = substr($line, strpos($line, ":") + 2);
							if (preg_match("/content-type/i", $lastheader)) {
								$message["headers"]["X-Parsed-Content-Type"] = substr($line, strpos($line, ":") + 2);
							}
						}
					}
					else {
						if ($message["boundary"] == "") {
							// get this from the content-type header:
							$message["boundary"] = trim(substr($message["headers"]["X-Parsed-Content-Type"], strpos($message["headers"]["X-Parsed-Content-Type"], "boundary=") + 9), " \t\n\r\0\x0B\"'");
						}
						if (rtrim($line) == "--" . $message["boundary"] || rtrim($line) == "--" . $message["boundary"] . "--") {
							// being new section, end the last one.
							$section++;
						}
						else {
							if (!isset($message["messageblocks"][$section])) {
								$message["messageblocks"][$section] = "";
							}
							$message["messageblocks"][$section] .= $line . "\n";
						}
					}
				}
			}

			// Parse the headers out of the sections.
			if (is_array($message["messageblocks"])) {
				foreach ($message["messageblocks"] as $sectionID => $content) {
					$headers = 1;
					$lastheader = "";
					$lines = preg_split("/\n/", $content);
					if ($sectionID == 0) {
						// first block is raw content since the headers have been parsed already for this block.
						$headers = 0;
					}
					foreach ($lines as $line) {
						if ($headers == 1) {
							if (trim($line) == "") {
								$headers = 0;
							}
							elseif (trim(substr($line, 0, 1)) == "") {
								// add this to the previous header.
								$message["parts"][$sectionID]["headers"][$lastheader] .= "\n" . rtrim($line);
							}
							else {
								$lastheader = substr($line, 0, strpos($line, ":"));
								$message["parts"][$sectionID]["headers"][$lastheader] = substr($line, strpos($line, ":") + 2);
								if (preg_match("/content-type/i", $lastheader)) {
									$message["parts"][$sectionID]["headers"]["X-Parsed-Content-Type"] = substr($line, strpos($line, ":") + 2);
								}
							}
						}
						else {
							if (!isset($message["parts"][$sectionID]["body"])) {
								$message["parts"][$sectionID]["body"] = "";
							}
							$message["parts"][$sectionID]["body"] .= $line . "\n";
						}
					}
					unset($message["messageblocks"][$sectionID]);
				}
			}

			if (count($message["messageblocks"]) == 0) {
				unset($message["messageblocks"]);
			}

			// if we were called to parse a section, return to sender here.
			if ($is_section) {
				return $message;
			}

			// dig deeper into sections with boundaries...
			foreach ($message["parts"] as $sectionID => $section) {
				if (isset($section["headers"]["X-Parsed-Content-Type"])) {
					if (strpos($section["headers"]["X-Parsed-Content-Type"], "boundary=")) {
						// This section has a boundary and needs to be parsed to find it's own parts...
						$message["parts"][$sectionID]["part"] = $this->parse($section["body"], true, $section["headers"]);
						unset($message["parts"][$sectionID]["body"]);
					}
				}
			}

			return ["data" => ["type" => "message", "id" => "0", "attributes" => $message]];
		}
		/**
		 * Find the mime type in the message if there is one in this email and return it.
		 *
		 * @param  array   $parsedEmail  email parsed by parse() function
		 * @param  string  $type         mime-type.
		 * @return string
		 */
		public function find($parsedEmail, $type, $is_part = false) {
			$regex = "/" . addcslashes($type, "/") . "/";
			if (!$is_part) {
				$message = $parsedEmail["data"]["attributes"];
			}
			else {
				$message = $parsedEmail;
			}

			if (isset($message["headers"]["X-Parsed-Content-Type"])) {
				if (preg_match($regex, $message["headers"]["X-Parsed-Content-Type"])) {
					$body = "";
					if ($is_part) {
						$body = $message["body"];
					}
					else {
						$body = $message["parts"][0]["body"];
					}
					if (isset($message["headers"]["Content-Transfer-Encoding"])) {
						return $this->decode($body, $message["headers"]["Content-Transfer-Encoding"]);
 					}
 					else {
 						return $body;
 					}
				}
				elseif (preg_match("/multipart/", $message["headers"]["X-Parsed-Content-Type"])) {
					if (!$is_part) {
						foreach ($message["parts"] as $part) {
							$search = $this->find($part, $type, true);
							if ($search != "") {
								return $search;
							}
						}
					}
					else {
						foreach ($message["part"]["parts"] as $part) {
							$search = $this->find($part, $type, true);
							if ($search != "") {
								return $search;
							}
						}
					}
				}
			}

			// Still here? return blank.
			return "";
		}

		/**
		 * Find the HTML message if there is one in this email and return it.
		 *
		 * @param  array   $parsedEmail  email parsed by parse() function
		 * @return string
		 */
		public function findHtml($parsedEmail) {
			return $this->find($parsedEmail, "text/html");
		}
		/**
		 * Find the plain text message if there is one in this email and return it.
		 *
		 * @param  array   $parsedEmail  email parsed by parse() function
		 * @return string
		 */
		public function findPlainText($parsedEmail) {
			return $this->find($parsedEmail, "text/plain");
		}

		/**
		 * Return the header specified from within the message headers.
		 *
		 * @param  array  $parsedEmail  email parsed by parse() function
		 * @param  string $header       header to return.
		 * @return string
		 */
		public function getHeader($parsedEmail, $header) {
			if (isset($parsedEmail["data"]["attributes"]["headers"][$header])) {
				return $parsedEmail["data"]["attributes"]["headers"][$header];
			}
			return "";
		}



		/**
		 * Decode the part using it's encoding header
		 *
		 * @param  string $partContents the content of this part of the message to be decoded
		 * @param  string $encoding     the Content-Transfer-Encoding header for this part
		 * @return string
		 */
		public function decode($partContents, $encoding) {
			if ($encoding == "quoted-printable") {
				return quoted_printable_decode($partContents);
			}
			elseif ($encoding == "base64") {
				return base64_decode($partContents);
			}
			else {
				return $partContents;
			}
		}
	}