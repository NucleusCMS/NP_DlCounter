<?php
/*
NP_DlCounter
Based on Simple Download Counter 1.0
by Drew Phillips (http://www.drew-phillips.com)

converted to a Nucleus Plugin by
Rodrigo Moraes (http://www.tipos.com.br)

History:
    0.5 - fix %2F encoding problem in file path
        - add unInstall
        - add support for <%DlCounter%> in item text/more
    0.6 - fix <%DlCounter%> skin var URL
        - fix URI problem in skinVar
    0.7 - fix a bug with NP_Print
    0.8 - add getTableList()
    1.0 - show file size
        - add admin menu
        - auto file path detection
    1.1 - Replace mysql_function to sql_function
        - Refactor
*/

class NP_DlCounter extends NucleusPlugin {
    function getMinNucleusVersion() { return '350';}
    function getName()              { return 'Download Counter'; }
    function getAuthor()            { return 'Drew Phillips | Rodrigo Moraes (conversion to plugin) | Edmond Hui (admun) | yama'; }
    function getURL()               { return 'http://www.drew-phillips.com'; }
    function getVersion()           { return '1.1'; }
    function supportsFeature($w)    { return ($w==='SqlTablePrefix') ? 1 : 0; }
    function getDescription()       { return 'A simple download counter.'; }
    function getEventList()         { return explode(',', 'PreSkinParse,PreItem,QuickMenu'); }
    function getTableList()         { array( sql_table('plug_dl_count') ); }
    function hasAdminArea()         { return 1; }

    function install() {
        $this->createOption('usecookie', 'Use cookie to avoid counting more than once a file download made by the same person?', 'yesno', 'yes');
        $this->createOption('showSize', 'Show file size', 'yesno', 'yes');
        $tbl_plug_dl_count = sql_table('plug_dl_count');
        sql_query("
        	CREATE TABLE IF NOT EXISTS {$tbl_plug_dl_count} (
            file VARCHAR(255) NOT NULL,
        	count INT NOT NULL default '0')
        	");
    }

    function event_QuickMenu(&$data) {
      // only show when option enabled
      global $member;
      if (!($member->isLoggedIn() || !$member->isAdmin())) return;
      
      $data['options'][] = 
      array(
      'title'   => 'DlCounter',
	  'url'     => $this->getAdminURL(),
	  'tooltip' => 'DlCounter Statistics'
      );
    }

    function event_PreSkinParse(&$data) {
        global $CONF, $DIR_MEDIA;
        
        if(!isset($_GET['file'])) return;
        
        $tbl_plug_dl_count = sql_table('plug_dl_count');
        $expire = time() + 60*60*24*365;
        
        $file = getVar('file');

        if(empty($file))                   exit('No File Specified');
        if(strpos($file, '..') !== FALSE)  exit('Error.');
        if(strpos($file, '://') !== FALSE) exit('Invalid File');
        //if the last two checks didnt get rid of a malicious user, this next one certainly will
        if(!is_file("{$DIR_MEDIA}{$file}"))   exit("Specified file {$file} doesn't exist.");

        // cookie fix
        $cookie = str_replace('.', '_', $file);  
        
        $query = "SELECT * FROM {$tbl_plug_dl_count} WHERE file='{$file}'";
        $result = sql_query($query);
        if(!$result) {
            echo sql_error();
            exit;
        }
        
        if(sql_num_rows($result) == 0) {
            //first use of this file
            $query = "INSERT INTO {$tbl_plug_dl_count} VALUES('{$file}', 1)";
            $result = sql_query($query);
            if ($this->getOption('usecookie') == 'yes')
                setcookie("dl_{$cookie}", 'set', $expire);
        }
        elseif(!isset($_COOKIE["dl_{$cookie}"]) || $this->getOption('usecookie') != 'yes') {
            $query = "UPDATE {$tbl_plug_dl_count} SET count=count+1 WHERE file='{$file}'";
            $result = sql_query($query);
            if ($this->getOption('usecookie') == 'yes')
                setcookie("dl_{$cookie}", 'set', $expire, $CONF['CookiePath'],$CONF['CookieDomain'],$CONF['CookieSecure']);
        }

        header("Location: " . $CONF['Self'] . "/media/{$file}");
    }

    function replaceCallback($matches) {
        global $manager, $blog;

        if ($blog === '')
        	$blog =& $manager->getBlog($CONF['DefaultBlog']);

		$member_name   = $matches[1];
		$file_name     = $matches[2];
		$linked_string = $matches[3];
		$mem = MEMBER::createFromName($member_name);
		return sprintf('<a href="%s?file=%s/%s">%s</a>', $blog->getURL(), $mem->getID(), $file_name, $linked_string);
    }

    function event_PreItem($data) {
		$this->currentItem = &$data['item'];
		$pattern = "#<\%DlCounter\((.*?)\,(.*?)\,(.*?)\)\%\>#";
		$this->currentItem->body = preg_replace_callback($pattern, array(&$this, 'replaceCallback'), $this->currentItem->body);
		$this->currentItem->more = preg_replace_callback($pattern, array(&$this, 'replaceCallback'), $this->currentItem->more);
    }

    function doSkinVar($skinType,$arg1='',$arg2='',$arg3='',$arg4='') {
        global $manager, $blog, $CONF, $cnumber, $c, $mp, $DIR_MEDIA;

		$mem = MEMBER::createFromName($arg1);
		$memberid = $mem->getID();
		
		if ($this->getOption('showSize') == 'yes') {
			$size = filesize("{$DIR_MEDIA}{$memberid}/{$arg2}") . ' bytes, download ';
		}

		$tbl_plug_dl_count = sql_table('plug_dl_count');
        $query = "SELECT count FROM {$tbl_plug_dl_count} WHERE file='{$memberid}/{$arg2}'";
        $result = sql_query($query);
        if(sql_num_rows($result) == 0)
            $count = '0';
        else {
            $counter = sql_fetch_row($result);
            $count = $counter[0];
        }

        if(!$arg2) $arg2 = 'Download file';
        
        if ($arg4 == '1') echo $count;
        else {
        	$blog_url = $blog->getURL();
            echo '<a href="' . "{$blog_url}?file={$memberid}/{$arg2}" . '">' . "{$arg3}</a> ({$size}{$count}x)";
        }
    }
}
