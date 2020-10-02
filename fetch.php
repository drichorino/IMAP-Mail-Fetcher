<?php
$mbox = imap_open('{torukojapan.sakura.ne.jp:993/imap/ssl}INBOX', 'info@gearupsolution.jp', 'denim7k2mv123') or die('Cannot connect to Sakura: ' . imap_last_error());
header('Content-Type: application/json');
if( $mbox ) {
   
     //Check no.of.msgs
	$info = imap_check($mbox);
	$max = 10;//最大50件取得
	for ($i = 0; $i < $max; $i++) {
		$msgno = $info->Nmsgs - $i;
		if ($msgno <= 0) break;
		//メールのヘッダー情報を取得
		$header = imap_header($mbox, $msgno);
		//メールのタイトルを取得
		$subject = getSubject($header);
		//echo $msgno.':'.$subject.'<br>';
		//echo '<textarea rows="20" cols="150">'.getBody($mbox, $msgno).'</textarea>';
				if(($msgno == 855)||($msgno == 851)){
					$body = getBodyMultipart($mbox, $msgno);
				} else {
					$body = getBody($mbox, $msgno);
				}
                //$body =  imap_qprint($body);
                $body = strip_tags($body);
                $body = trim(preg_replace('/\s+/', ' ', $body));
                //echo '<textarea rows="20" cols="150">'.$body.'</textarea>';
				//echo '<br /><br />';
				//$body = mb_convert_encoding($body, 'UTF-8', 'iso-2022-jp');
				$body_array = array($body => "ham");
				$data[]=$body_array;
				/*
				if($msgno == 708){
					$file = 'body'.$msgno.'.txt';
					file_put_contents($file,$body);
				}				
				*/
                //$file = 'body'.$msgno.'.txt';
                //file_put_contents($file,$body);
	}
	imap_close($mbox);

	header("Content-Type: text/csv; charset=UTF-8");
	$file = fopen('data.csv','wb');
	fputs($file, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
	$i = 1;
	foreach($data as $row){
		$keys = array_keys($row);
		fputcsv($file, array($i, $keys[0], $row[$keys[0]]));
		$i++;
	}
	fclose($file);
	die();
	//export_data_to_csv($data);
	
}

/*
function export_data_to_csv($data,$filename='export',$delimiter = ';',$enclosure = '"')
{
    // Tells to the browser that a file is returned, with its name : $filename.csv
    header("Content-disposition: attachment; filename=$filename.csv");
    // Tells to the browser that the content is a csv file
    header("Content-Type: text/csv; charset=UTF-8");

    // I open PHP memory as a file
    $fp = fopen("php://output", 'w');

    // Insert the UTF-8 BOM in the file
    fputs($fp, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

    // I add the array keys as CSV headers
    fputcsv($fp,$data);

    // Add all the data in the file
    //foreach ($data as $fields) {
    //    fputcsv($fp, $fields,$delimiter,$enclosure);
    //}

    // Close the file
    fclose($fp);

    // Stop the script
    die();
}
*/


function getBodyMultipart($mbox, $msgno)
{
		//get the body
	$body = imap_fetchbody($mbox, $msgno, 1);
	$header = imap_fetchbody($mbox, $msgno, 0);
	
	echo "HEADER: ";
	echo PHP_EOL.$header;
	echo '===================== END HEADER ===================='.PHP_EOL.PHP_EOL;	

	echo "BODY: ";
	echo PHP_EOL.$body;
	echo '===================== END BODY ===================='.PHP_EOL.PHP_EOL;	

	//parse the boundary separator
	$matches = array();
	//preg_match('#Content-Type: multipart\/[^;]+;\s*boundary="([^"]+)"#i', $header, $matches);
	preg_match('/--mark=_\d+/i', $body, $matches);
	list($boundary) = $matches;
	
	if(empty($boundary)){
		preg_match('/\S+=\S\d+\SNextPart\d+\S=\S+/i', $body, $matches);
		list($boundary) = $matches;
	}
	
	echo 'MATCHES: ';
	print_r($matches);
	echo PHP_EOL;
	echo 'BOUNDARY: '.$boundary.PHP_EOL;

	$text = '';
	if(!empty($boundary)) {

		//split the body into the boundary parts
		$emailSegments = explode($boundary, $body);

		//get the plain text part
		foreach($emailSegments as $segment) {
			echo 'SEGMENT: '.PHP_EOL.$segment.PHP_EOL;
			echo '======================================================================='.PHP_EOL;
			if(stristr($segment, 'Content-Type: text/plain') !== false) {
				$text = trim(preg_replace('/Content-(Type|ID|Disposition|Transfer-Encoding):.*?\r\n/is', '', $segment));
				$text = trim(preg_replace('/charset=[\S+]+/i', '', $text));
				echo PHP_EOL.'WENT HERE!!!!'.PHP_EOL;
				break;
			}
		}
	}
	
	$text = imap_base64($text);
	$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	echo 'TEXT:'.PHP_EOL.'['.$text.']'.PHP_EOL;
	echo PHP_EOL.'========================== END TEXT ============================'.PHP_EOL.PHP_EOL;	
	return $text;
}


function getSubject($header)
{
	if (!isset($header->subject)) {
		//タイトルがない場合もある
		return '';
	}
	// タイトルをデコード
	$mhead = imap_mime_header_decode($header->subject);
	$subject = '';
	//タイトル部分は分割されているのでコード変換しながら結合する
	foreach($mhead as $key => $value) {
		if($value->charset == 'default') {
			$subject .= $value->text; //変換しない
		} else {
			$subject .= mb_convert_encoding($value->text, 'UTF-8', $value->charset);
			//$subject .= mb_convert_encoding($value->text,'UTF-8', 'iso-2022-jp');
		}
	}
	return $subject;
}

//メール本文の取得（マルチパートほんのり対応）
function getBody($mbox, $msgno)
{
	//マルチパートだろうとそうでなかろうと1個目を取得する
	$body = (imap_fetchbody($mbox, $msgno, 1, FT_INTERNAL));

	//メールの構造を取得
	$s = imap_fetchstructure($mbox, $msgno);

	//マルチパートのメールかどうか確認しつつ、文字コードとエンコード方式を確認
	if (isset($s->parts)) {
		//マルチパートの場合
		$charset = $s->parts[0]->parameters[0]->value;
		$encoding = $s->parts[0]->encoding;
		echo 'HERE! ';
		if($msgno == 855){
			echo PHP_EOL;
			$x = imap_fetchheader($mbox,$msgno); 
			//print_r(json_encode($x));
			//print_r($x);
			echo PHP_EOL;
		}		
		if ((stripos($charset, 'mark') !== false) || (stripos($charset, 'part') !== false)) {
			$charset = $s->parts[0]->parts[0]->parameters[0]->value;
				if ((stripos($charset, 'mark') !== false) || (stripos($charset, 'part') !== false)) {
					$charset = $s->parts[0]->parts[0]->parts[0]->parameters[0]->value;
				}
		}
	} else {
		//マルチパートではない場合
		ECHO 'HERE 2! ';	
		$charset = $s->parameters[0]->value;
		$encoding = $s->encoding;
	}
	
	$body2=$body;

	echo "ENCODING $msgno: ".$encoding.PHP_EOL;
	echo "CHARSET :".$charset.PHP_EOL;
	

	//エンコード方式に従いデコードする
	switch ($encoding) {
		case 1://8bit
			$body = imap_8bit($body);
			$body = imap_qprint($body);
			break;
		case 3://Base64
			$body = imap_base64($body);
			break;
		case 4: //Quoted-Printable
			$body = imap_qprint($body);
			break;
		case 0: //7bit
		case 2: //Binary
		case 5: //other
		default:
			//7bitやBinaryは何もしない
	}
	
	
	//メールの文字コードをUTF-8へ変換する
	$body = mb_convert_encoding($body, 'UTF-8', $charset);
	
	if($msgno == 99999999){
		$body = base64_decode($body2);		
		$body = mb_convert_encoding($body,'SJIS-win', 'UTF-8');
	}
	
	//$body = mb_convert_encoding($body,'UTF-8', 'iso-2022-jp');

	return $body;
}





?>