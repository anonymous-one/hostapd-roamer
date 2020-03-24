<?php
function logToConsole($string){ // simple console logger
        echo date('Y-m-d H:i:s',time()).' | '.$string."\n";
}
function ascii2hex($ascii){
        $hex='';
        for($i=0;$i<strlen($ascii);$i++){
                $byte=strtoupper(dechex(ord($ascii{$i})));
                $byte=str_repeat('0',2-strlen($byte)).$byte;
                $hex.=$byte;
        }
        return $hex;
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
        // duration *** still have not figured this out fully, but this value works well for me ***
        $parts[]='3000';
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
function sortBeaconCheck($freq,$candidates){
        /*
        sort the beaconcheck list according to the passed candidate rssi list:

        for beaconcheck lists that have 2 or more entries this will make the final check a bit quicker
        the entry with the highest rssi will be at the top of the list and the script will skip all other
        entries if it meets the required minimum rssi

        think of it this way, as you are walking towards a potential roam-to access point
        your signal will get stronger and stronger until the rssi is strong enough for a roam-to
        its this entry we want to have at the top of the beaconcheck list so when it meets the
        minimum rssi, everything else is skipped and we send over a bss-tm

        eg:
        2nd to last cycle : AP2 - 70, AP3 - 66, AP1 - 72
        last cycle : AP3 - 64 (enough for a roam), does not check other entries
        */
        global $beaconcheck;
        // cannot sort a list with less than 2 entries
        if(count($beaconcheck[$freq])<2){
                return false;
        }
        // sort the candidate list according to rssi
        asort($candidates);
        $final=array();
        foreach($candidates as $candidate=>$rssi){
                $final[]=$candidate;
        }
        // add all entries that are not already in the final incase the returnd a false (not in range) or are our own mac address
        foreach($beaconcheck[$freq] as $mac){
                if(in_array($mac,$final)){
                        continue;
                }
                $final[]=$mac;
        }
        // if the order of the list has changed, send out a logToConsole
        if(serialize($final)!=serialize($beaconcheck[$freq])){
                logToConsole('Beaconcheck frequency '.$freq.' order has been modified');
                return $final;
        }
        else{
                return false;
        }
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
                        usleep(100000);
                        continue;
                }
                else{
                        $rssidata['rssi']=$result;
                }
                if($rssidata['freq']==5&&$rssidata['rssi']>=$data['rssi-5-to-other']){ // see if we should roam from AP1 5ghz to AP2 5ghz
                        $candidates=array();
                        foreach($beaconcheck[5] as $key=>$target){ // send out a beacon request to each of the APs we could possibly roam to
                                if(!isset($beaconcache[$target])||microtime(true)-$beaconcache[$target][0]>0.1){ // just in case, lets cache this data for 100ms
                                        $params=array();
                                        $params['stamac']=$mac;
                                        $params['staadapter']=$data['adapters'][5][0];
                                        $params['targetmac']=$target;
                                        $params['targetnr']=$neigh[$target];
                                        $result=fetchRssi($params);
                                        if($result===false){
                                                $beaconcache[$target]=array((microtime(true)+0.2),$result);
                                        }
                                        else{
                                                $beaconcache[$target]=array(microtime(true),$result);
                                        }
                                }
                                else{
                                        $result=$beaconcache[$target][1];
                                }
                                if($result===false){ // no beacon response? continue to the next ap
                                        continue;
                                }
                                elseif($target==$data['adapters'][5][1]){ // are we checking the current connected to ap? replace the rssi
                                        $rssidata['rssi']=$result;
                                }
                                elseif($result<=$data['rssi-5-to-other-min']){ // does the rssi to the candidate meet the minimum required rssi? if so, store as a possible target
                                        $candidates[$target]=$result;
                                        if($result<$rssidata['rssi']){ // maybe we do not need to check any other access points
                                                break;
                                        }
                                }
                        }
                        foreach($candidates as $candidatemac=>$candidaterssi){ // lets check each of the possible candidates
                                if($candidatemac==$rssidata['apmac']){ // candidate is the same ap as already connected to? continue onto next result
                                        continue;
                                }
                                elseif($candidaterssi<$rssidata['rssi']){ // is the rssi to the candidate lower than the currently connected to ap? force a roam.
                                        $command='hostapd_cli -i '.$data['adapters'][5][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($candidatemac).' pref=1 abridged=1 2>&1';
                                        $output=array();
                                        exec($command,$output);
                                        logToConsole('Candidate '.$candidatemac.' has rssi of '.$candidaterssi.', forced roam via '.$command.' ['.trim(implode(' ',$output)).']');
                                        usleep(500000);
                                        continue 2;
                                }
                        }
                        // see if we can re-sort the beaconcheck list
                        if($sorted=sortBeaconCheck(5,$candidates)){
                                $beaconcheck[5]=$sorted;
                        }
                }
                elseif(isset($data['adapters'][5])&&$rssidata['freq']==2&&$rssidata['rssi']<=$data['rssi-2-to-5']&&$rssidata['connectedtime']>=2){ // are we connected to the 2.4ghz band + have a strong signal? try to roam to 5ghz band on the same AP
                        // send out a beacon report request to the 5ghz band ap
                        $params=array();
                        $params['stamac']=$mac;
                        $params['staadapter']=$data['adapters'][2][0];
                        $params['targetmac']=$data['adapters'][5][1];
                        $params['targetnr']=$neigh[$data['adapters'][5][1]];
                        $result=fetchRssi($params);
                        if($result!==false&&$result<=65){ // do we have a response and the rssi is good? force a roam.
                                $command='hostapd_cli -i '.$data['adapters'][2][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($data['adapters'][5][1]).' pref=1 abridged=1 2>&1';
                                $output=array();
                                exec($command,$output);
                                logToConsole('Forced '.$mac.' to roam to 5ghz via '.$command.' ['.trim(implode(' ',$output)).']');
                                // for 3 seconds check if the client changed frequencies
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
                elseif($rssidata['freq']==2&&$rssidata['rssi']>=70&&$rssidata['connectedtime']>=2){ // are we connected to the 2.4ghz band + have a fairly weak signal? try to roam to either a 5ghz AP or 2.4ghz AP
                        $candidates=array();
                        foreach($beaconcheck[2] as $key=>$target){ // send out a beacon request to each of the APs we could possibly roam to
                                if(!isset($beaconcache[$target])||microtime(true)-$beaconcache[$target][0]>0.1){ // just in case, lets cache this data for 100ms
                                        $params=array();
                                        $params['stamac']=$mac;
                                        $params['staadapter']=$data['adapters'][2][0];
                                        $params['targetmac']=$target;
                                        $params['targetnr']=$neigh[$target];
                                        $result=fetchRssi($params);
                                        if($result===false){
                                                $beaconcache[$target]=array((microtime(true)+0.2),$result);
                                        }
                                        else{
                                                $beaconcache[$target]=array(microtime(true),$result);
                                        }
                                }
                                else{
                                        $result=$beaconcache[$target][1];
                                }
                                if($result===false){ // no beacon response? continue to the next ap
                                        continue;
                                }
                                elseif($target==$data['adapters'][2][1]){ // are we checking the current connected to ap? replace the rssi
                                        $rssidata['rssi']=$result;
                                }
                                elseif($result<=$data['rssi-5-to-other-min']){ // does the rssi to the candidate meet the minimum required rssi? if so, store as a possible target
                                        $candidates[$target]=$result;
                                        if($result<($rssidata['rssi']-5)){ // maybe we do not need to check any other access points
                                                break;
                                        }
                                }
                        }
                        foreach($candidates as $candidatemac=>$candidaterssi){ // lets check each of the possible candidates
                                if($candidatemac==$rssidata['apmac']){ // candidate is the same ap as already connected to? continue onto next result
                                        continue;
                                }
                                elseif($candidaterssi<($rssidata['rssi']-5)){ // is the rssi to the candidate lower than the currently connected to ap? force a roam.
                                        $command='hostapd_cli -i '.$data['adapters'][2][0].' bss_tm_req '.$mac.' neighbor='.neighborFromMac($candidatemac).' pref=1 abridged=1 2>&1';
                                        $output=array();
                                        exec($command,$output);
                                        logToConsole('Candidate '.$candidatemac.' has rssi of '.$candidaterssi.', forced roam via '.$command.' ['.trim(implode(' ',$output)).']');
                                        usleep(500000);
                                        continue 2;
                                }
                        }
                        // see if we can re-sort the beaconcheck list
                        if($sorted=sortBeaconCheck(2,$candidates)){
                                $beaconcheck[2]=$sorted;
                        }
                }
        }
        usleep(500000);
}

?>
