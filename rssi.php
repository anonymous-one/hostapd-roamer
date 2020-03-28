<?php
function logToConsole($string){ // simple console logger
        echo date('Y-m-d H:i:s',time()).' | '.$string."\n";
}
function ascii2hex($ascii){ // convert ascii to hex
        $hex=array();
        for($i=0;$i<strlen($ascii);$i++){
                $byte=strtoupper(dechex(ord(substr($ascii,$i,1))));
                $byte=str_repeat('0',2-strlen($byte)).$byte;
                $hex[]=$byte;
        }
        return implode($hex);
}
function getDeviceRSSI($mac){ // fetch station details using iw, check all adapters, and use the freshest one
        global $data;
        $final=array('freq'=>false,'inactivetime'=>false);
        foreach($data['adapters'] as $freq=>$device){
                $command='iw dev '.$device[0].' station get '.$mac.' 2>&1';
                $output=array();
                exec($command,$output);
                if(!is_array($output)||!count($output)){
                        continue;
                }
                $output=implode($output);
                if(preg_match('/^Station\s'.preg_quote($mac).'.*inactive\stime\:[\s\t]+([0-9]+)\sms.*rx\spackets\:[\s\t]+([0-9]+)[\s\t]+.*signal\:[\s\t]+\-([0-9]+)[\s\t]+.*connected\stime\:[\s\t]+([0-9]+)[\s\t]+/isU',$output,$matches)){
                        if($final['inactivetime']===false||$final['inactivetime']>$matches[1]){
                                $final['freq']=$freq;
                                $final['rssi']=$matches[3];
                                $final['inactivetime']=$matches[1];
                                $final['connectedtime']=$matches[4];
                                $final['rxpackets']=$matches[2];
                                $final['apmac']=$device[1];
                                $final['apdevice']=$device[0];
                        }
                }
        }
        // if freq is false, we can assume the client is not connected
        if($final['freq']===false){
                return false;
        }
        else{
                return $final;
        }
}
function fetchRssi($params,$timeout=5){ // send out a beacon request, and wait for hostapd_cli to store it in the temp file
        global $data;
        $target=neighborFromMac($params['targetmac'],false);
        $parts=array();
        // op class
        $parts[]=str_pad(dechex($target['opclass']),2,'0',STR_PAD_LEFT);
        // channel
        $parts[]=str_pad(dechex($target['chan']),2,'0',STR_PAD_LEFT);
        // randomization interval *** not sure what this is used for, sounds like how long to wait before starting a scan ***
        $parts[]='0000';
        // duration *** still have not figured this out fully, but i am using this to identify the beacon request ***
        $id=rand(25,35);
        $rand=str_pad(dechex($id),2,'0',STR_PAD_LEFT).'00';
        $parts[]=$rand;
        // mode [ 0 = passive, 1 = active, 2 = table ]
        $parts[]='01';
        // target mac
        $parts[]=strtoupper(str_replace(':','',$params['targetmac']));
        $packet=implode($parts);
        // where we are expecting the beacon response to be saved to via the hostapd_cli -a script
        $beaconfile='/tmp/beaconresp.'.$params['stamac'];
        // if for some reason its already exists, remove it
        clearstatcache();
        if(is_file($beaconfile)){
                unlink($beaconfile);
        }
        // send out the beacon request
        $command='hostapd_cli -i '.$params['staadapter'].' req_beacon '.$params['stamac'].' '.$packet.' 2>&1 > /dev/null';
        system($command);
        $start=time();
        while(time()-$start<=$timeout){ // wait for $timeout seconds for a response, give up otherwise
                clearstatcache();
                if(is_file($beaconfile)){ // we have a response
                        $content=file_get_contents($beaconfile);
                        if($content=='FAILED'){
                                return NULL;
                        }
                        elseif(strlen($content)<64){ // 64 is just a random length, but these beacon responses are quite long, so 64 is enough
                                return false;
                        }
                        // parse out the returned mac address
                        $mac=substr($content,30,12);
                        if($mac!=str_replace(':','',$params['targetmac'])){
                                return NULL;
                        }
                        // parse out the id of the request
                        if(hexdec(substr($content,20,2))!=$id){
                                return NULL;
                        }
                        $rssi=unpack("l", pack("l", hexdec("FFFFFF".strtoupper(substr($content,26,2)))))[1]; // pull out the rssi from the beacon response
                        // the rssi is returned in a -XXdBm format, convert to positive to make more readable
                        return ($rssi*-1);
                }
                usleep(50000);
        }
        // no response in 5 seconds
        return false;
}
function neighborFromMac($mac,$returnparsed=true){ // parse out various details from a neighbor report and return as array or hostapd_cli neighbor=___ string
        global $neigh;
        if(!isset($neigh[$mac])){
                return false;
        }
        preg_match('/^'.str_replace(':','',$mac).'([a-z0-9]{8})([a-z0-9]{2})([a-z0-9]{2})([a-z0-9]{2})/',$neigh[$mac],$matches);
        $op=hexdec($matches[2]);
        $chan=hexdec($matches[3]);
        $phy=(int)$matches[4];
        if($returnparsed){ // we are returning as a string for hostapd_cli to use
                $final=$mac.',0x0000,'.$op.','.$chan.','.$phy;
        }
        else{ // we are returning as an array
                $final=array();
                $final['opclass']=$op;
                $final['chan']=$chan;
                $final['phytype']=$phy;
        }
        return $final;
}

// include the config file
include('rssi.config.php');

// when the script is started, purge the neighbor report for each of the adapters
foreach($data['adapters'] as $freq=>$device){
        if($data['servertype']=='openwrt'){
                $command='ubus call hostapd.'.$device[0].' rrm_nr_set \'{"list":[]}\'';
                system($command);
        }
        elseif($data['servertype']=='hostapd'){
                $output=array();
                exec('hostapd_cli -i '.$device[0].' show_neighbor',$output);
                $output=implode("\n",$output);
                if(preg_match_all('/([a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2})/i',$output,$matches)){
                        foreach($matches[1] as $mac){
                                // do not remove our own neighbor report
                                if($mac==$device[1]){
                                        continue;
                                }
                                system('hostapd_cli -i '.$device[0].' remove_neighbor '.$mac.' 2>&1 > /dev/null');
                        }
                }
        }
}

// this script should never timeout
set_time_limit('0');

// infinite loop starts
while(true){
        // just in case hostapd was restarted or maybe dropped the neighbor reports (not sure if they expire?), update them every 2 minutes
        if(time()-$data['lastneighupdate']>=120){
                $data['lastneighupdate']=time();
                foreach($data['adapters'] as $freq=>$device){
                        // pull the neighbor table from hostapd to prevent re-entering the same entry every time this block is executed
                        $present=array();
                        if($data['servertype']=='hostapd'){
                                $command='hostapd_cli -i '.$device[0].' show_neighbor';
                        }
                        else{
                                $command='ubus call hostapd.'.$device[0].' rrm_nr_list';
                        }
                        $output=array();
                        exec($command,$output);
                        // yes, ubus returns a nice json but just incase someone out there does not have the json module installed due to storage considerations, just preg_match it
                        if(preg_match_all('/([a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2}\:[a-z0-9]{2})/i',implode("\n",$output),$matches)){
                                $present=$matches[1];
                        }
                        foreach($neigh as $mac=>$nr){
                                // do not overwrite our own neighbor report
                                if(($freq==2&&isset($data['adapters'][2][1])&&$data['adapters'][2][1]==$mac)||($freq==5&&isset($data['adapters'][5][1])&&$data['adapters'][5][1]==$mac)){
                                        continue;
                                }
                                elseif(in_array($mac,$present)){ // do not overwrite / reinsert already stored neighbors
                                        continue;
                                }
                                // add the neighbor report entry to the list
                                $command='hostapd_cli -i '.$device[0].' set_neighbor '.$mac.' ssid='.strtolower(ascii2hex($data['bssid'])).' nr='.$nr.' >/dev/null 2>&1';
                                system($command);
                        }
                }
        }
        // i only have 1 roamer i need to worry about, but this lays the foundation for making this script handle more than one
        foreach($roamers as $mac=>$devicedata){
                if(!$rssidata=getDeviceRSSI($mac)){ // fetch rssi via iw, if not connected, wait 50ms and try again
                        $roamers[$mac]['failcount']=0;
                        usleep(50000);
                        continue;
                }
                elseif($rssidata['connectedtime']<1){ // wait until client has been connected for at least 1 second
                        usleep(50000);
                        continue;
                }
                // fetch own rssi so its nice and fresh
                $params=array();
                $params['stamac']=$mac;
                $params['staadapter']=$rssidata['apdevice'];
                $params['targetmac']=$rssidata['apmac'];
                $params['targetnr']=$neigh[$rssidata['apmac']];
                $result=fetchRssi($params,1);
                if($result===NULL||$result===false){
                        $roamers[$mac]['failcount']=(isset($roamers[$mac]['failcount'])?$roamers[$mac]['failcount']+1:1);
                }
                else{
                        $roamers[$mac]['failcount']=0;
                        $rssidata['rssi']=$result;
                }
                if($roamers[$mac]['failcount']>=10){
                        $command='hostapd_cli -i '.$rssidata['apdevice'].' deauthenticate '.$mac.' 2>&1';
                        logToConsole('Deauthenticating '.$mac.' via '.$command.' ['.trim(shell_exec($command)).']');
                        sleep(1);
                        $roamers[$mac]['failcount']=0;
                        continue;
                }
                elseif(in_array($result,array(NULL,false))){
                        usleep(50000);
                        continue;
                }
                if(isset($data['adapters']['5'])&&$rssidata['freq']==5&&$rssidata['rssi']>=$data['rssi-5-to-other']){ // connected at 5 + rssi above rssi-5-to-other = try to roam to $beaconcheck[5]
                        $candidates=array();
                        foreach($beaconcheck[5] as $key=>$target){
                                $params=array();
                                $params['stamac']=$mac;
                                $params['staadapter']=$data['adapters'][5][0];
                                $params['targetmac']=$target;
                                $params['targetnr']=$neigh[$target];
                                $result=fetchRssi($params);
                                if($result!==NULL&&$result!==false&&$result<=$data['rssi-5-to-other-min']){
                                        $candidates[$target]=$result;
                                }
                        }
                        asort($candidates);
                        foreach($candidates as $candidatemac=>$candidaterssi){
                                $command='hostapd_cli -i '.$data['adapters'][5][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($candidatemac).' pref=1 abridged=1 2>&1';
                                logToConsole('['.$mac.' @ '.$rssidata['freq'].':'.$rssidata['rssi'].'] Candidate '.$candidatemac.' has rssi of '.$candidaterssi.', forced roam via '.$command.' ['.trim(shell_exec($command)).']');
                                usleep(500000);
                                continue 2;
                        }
                }
                elseif(isset($data['adapters']['2'])&&$rssidata['freq']==2&&$rssidata['rssi']<=$data['rssi-2-to-5']){ // connected at 2 + rssi below rssi-2-to-5 = try to roam to same ap 5
                        $params=array();
                        $params['stamac']=$mac;
                        $params['staadapter']=$data['adapters'][2][0];
                        $params['targetmac']=$data['adapters'][5][1];
                        $params['targetnr']=$neigh[$data['adapters'][5][1]];
                        $result=fetchRssi($params);
                        if($result!==NULL&&$result!==false&&$result<=$data['rssi-2-to-5-min']){
                                $command='hostapd_cli -i '.$data['adapters'][2][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($data['adapters'][5][1]).' pref=1 abridged=1 2>&1';
                                logToConsole('['.$mac.' @ '.$rssidata['freq'].':'.$rssidata['rssi'].'] 5ghz band has rssi of '.$result.', forced roam via '.$command.' ['.trim(shell_exec($command)).']');
                                $start=time();
                                while(time()-$start<=3){
                                        $roamdata=getDeviceRSSI($mac);
                                        if(!is_array($roamdata)){
                                                usleep(50000);
                                                continue;
                                        }
                                        elseif($roamdata['freq']==5){
                                                logToConsole('Success roaming to 5ghz!');
                                                continue 2;
                                        }
                                        usleep(50000);
                                }
                                logToConsole('Failed roaming to 5ghz!');
                                continue;
                        }
                }
                elseif(isset($data['adapters']['2'])&&$rssidata['freq']==2&&$rssidata['rssi']>=$data['rssi-2-to-other']){ // connected at 2 + rssi above rssi-2-to-other = try to roam to $beaconcheck[2]
                        $candidates=array();
                        foreach($beaconcheck[2] as $key=>$target){
                                $params=array();
                                $params['stamac']=$mac;
                                $params['staadapter']=$data['adapters'][2][0];
                                $params['targetmac']=$target;
                                $params['targetnr']=$neigh[$target];
                                $result=fetchRssi($params);
                                if($result!==NULL&&$result!==false&&$result<=$data['rssi-2-to-other-min']){
                                        $candidates[$target]=$result;
                                }
                        }
                        asort($candidates);
                        foreach($candidates as $candidatemac=>$candidaterssi){
                                $command='hostapd_cli -i '.$data['adapters'][2][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($candidatemac).' pref=1 abridged=1 2>&1';
                                logToConsole('['.$mac.' @ '.$rssidata['freq'].':'.$rssidata['rssi'].'] Candidate '.$candidatemac.' has rssi of '.$candidaterssi.', forced roam via '.$command.' ['.trim(shell_exec($command)).']');
                                usleep(500000);
                                continue 2;
                        }
                }
        }
        usleep(500000);
}

?>
