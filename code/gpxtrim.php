<?php
/**
 * GPXファイルの調整用スクリプト。
 * 重複点の削除や、時間の修正を行う。
 * @author YuTanaka@AmuseOne
 * @copyright 2014 YuTanaka@AmuseOne
 */

define('DEBUG',false);

if (DEBUG) {
	echo "post:";
	foreach($_POST as $k => $v) {
		echo ("post[$k]=$v / ");
	}
	echo "<br />";
	echo "file";
	print_r($_FILES);
}

// 起動時など。処理なし
define('PR_NONE',0);
// GPX処理
define('PR_CONVGPX',1);

// 通常画面表示
define('SC_TOP',0);
// 変換したGPXを返す
define('SC_RETGPX',1);
// エラー
define('SC_ERR',2);

// 処理コマンド：中抜き
define('CMD_AIDA','rm_aida');
// 処理コマンド：以降
define('CMD_USHIRO','rm_ushiro');
// 処理コマンド無効
define('CMD_INVALID','rm_inv');

$proc = PR_NONE;
$sc = SC_TOP;
$err = "";
$xml = "";

// 処理の決定
selProc();

// 処理を実行
doProc();

// 結果を返す
resProc();

/**
 * 処理を決める
 */
function selProc() {
	global $proc,$sc;
	global $err;

	/*
	echo getCmd($_POST['convtype']);
	if (isset($_FILES)) {
		echo "files=true";
	}
	else {
		echo "files=false";
	}
	echo "count=".count($_FILES);
	*/
	
	// データがそろっているかをチェック
	if (	(getCmd($_POST['convtype']) !== CMD_INVALID)
		&&	(isset($_FILES) && (count($_FILES) > 0)))
	{
		// ファイルが存在するか
		foreach($_FILES as $k => $v) {
			if (!is_uploaded_file($v['tmp_name'])) {
				$proc = PR_NONE;
				$sc = SC_ERR;
				$err = "[".$v['name']."]がアップロードされていませんでした。";
				return;
			}
		}
		
		// 問題なければコンバート処理へ
		$proc=PR_CONVGPX;
	}

	/*
	echo "<p>proc=$proc / sc=$sc</p>";
	*/
}

/**
 * 与えられた文字列を判定して、コマンドを返す
 * @param string $txt 判定したい文字列。
 * @return string CMD_????のうちのどれか
 */
function getCmd($txt) {
	if (isset($txt)) 
	{
		if ($txt===CMD_AIDA) {
			return CMD_AIDA;
		}
		else if ($txt === CMD_USHIRO) {
			return CMD_USHIRO;
		}
	}
	return CMD_INVALID;
}

/**
 * 処理を実行する
 */
function doProc() {
	global $proc,$sc;
	
	switch($proc){
		// GPXを処理する
		case PR_CONVGPX:
			$sc=SC_RETGPX;
			cvProc();
			break;
	}
}

/**
 * GPX処理
 */
function cvProc() {
	global $sc,$err;
	global $xml;
	
	// ファイルをXMLに入れる
	foreach($_FILES as $k => $v) {
		if (file_exists($v['tmp_name'])) {
			$xml = new DOMDocument();
			if ($xml->load($v['tmp_name'])) {
				// 処理前
				if (DEBUG) {
					echo "<p>xml</p>";
					print_r($xml);
				}
				
				// 処理実行
				cvProcDo($xml);	
			}
			else {
				$sc = SC_ERR;
				$err = "[".$v['name']."]はGPXファイルではありませんでした。";
				return;
			}
		}
		else {
			$sc = SC_ERR;
			$err = 'ファイルがアップロードされていませんでした。';
		}
	}
}

/**
 * XMLElementの処理を実行する
 * @param DOMDocument $dom 処理するDOMオブジェクト
 */
function cvProcDo($dom) {
	global $sc,$err;
	
	$xml = $dom->documentElement;
	
	if (DEBUG) {
		echo "<p>domelement</p>";
		print_r($xml);
	}

	// gpxかを確認
	if ($xml->tagName !== "gpx") {
		$err = "このファイルはGPXファイルではありません。";
		$sc = SC_ERR;
		return;
	}
	
	// trkptタグを取り出す
	$nodes = $xml->getElementsByTagName('trkpt');
	
	// 同一データの開始位置
	$node_st = 0;
	$lon_st="";
	$lat_st="";
	$rmtrks = array();

	// 同一チェック
	$orig_trk_num = $nodes->length;
	for ($i=0 ; $i<$nodes->length ; $i++) {
		$trkpt = $nodes->item($i);
		if(		(($attlon = $trkpt->getAttributeNode('lon'))===false)
			||	(($attlat=$trkpt->getAttributeNode('lat'))===false))
		{
			continue;
		}
		
		// 時間の変換をするかを確認
		$trktm = $trkpt->getElementsByTagName('time');
		$tm = strtotime($trktm->item(0)->nodeValue);
		if (isset($_POST['chgtime']) && strlen($_POST['chgtime'])>0) {
			// 計算を実行
			$tm = $tm+intval($_POST['txttime'])*60*60;
			// 更新する。GPXはUTCが基準なので、タイムゾーンをあわせる
			$trktm->item(0)->nodeValue = gmdate("Y-m-d",$tm)."T".gmdate("H:i:s",$tm)."Z";
		}
		
		// 位置が変更になった
		if (	(strcmp($attlat->value,$lat_st) !== 0)
			||	(strcmp($attlon->value,$lon_st) !== 0))
		{
			// これまでの同一点があれば処理をする
			$end = $i;
			if (getCmd($_POST['convtype']) === CMD_AIDA) {
				// 最初と最後の間を中抜きする
				$end--;
			}
			for ($j=$node_st+1 ; $j<$end ; $j++) {
				$rmtrks[] = $nodes->item($j);
			}
				
			// 更新する
			$node_st = $i;
			$lat_st = $attlat->value;
			$lon_st = $attlon->value;
		}
		
		// まとめて削除する
		foreach($rmtrks as $rmtrk) {
			try {
				if ($rmtrk->parentNode) {
					$rmtrk->parentNode->removeChild($rmtrk);
				}
				//$nodes = $nodes->removeChild($rmtrk);
			} catch (Exception $e) {
				$err = "<P>Exception:".$e->getMessage()."</P>";
				$sc = SC_ERR;
			}
		}
	}
		/*
				
			}
		}
	}
	*/
}

/**
 * 結果を表示する
 */
function resProc() {
	global $sc;
	global $err;
	global $xml;
	
	switch ($sc) {
		// 受付画面表示
		case SC_TOP:
			printTop();
			break;
			
		// 変換したGPXをかえす
		case SC_RETGPX:
			// ダウンロード用のヘッダを出力する
			$ret = $xml->saveXML();
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename='.$_FILES['gpxfile']['name']);
			header('Content-Length: '.strlen($ret));
			echo $ret;
			break;
		
			// エラー
		case SC_ERR:
			echo "<!DOCTYPE html>";
			echo "<html><head>";
			echo "<meta charset='UTF-8'>";
			echo "<title>GPX Trimmer</title>";
			echo "</head>";
			echo "<body>";
			
			echo "<p>error:".$err."<br />";
			echo "<a href='./index.html'>Back</a>";
			echo "</p>";
			echo "</body>";
			echo "</html>";
			break;
	}	
}

/**
 * トップ画面の表示
 */
function printTop() {
}

?>
