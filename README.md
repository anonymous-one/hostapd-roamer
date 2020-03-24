**Hostapd-roamer**

This is a simple PHP based script that will streer a client device to multiple access points using hostapd / OpenWRT's ubus hostapd interface.

It's not super polished, but works quite well for me. Don't expect to have it running perfectly without making some changes. I am posting this strictly because I think someone out there will find it useful.

It is a bit tailored to my home, which has a dual band access point on each floor. This means that I have a strong 5ghz signal everywhere. So it is setup to try and get the client on to a 5ghz access point as quickly as possible. More details how this is done below.

**Requirements**

There are quite a few:

**A) OpenWRT**

OpenWRT 19 or higher. This script makes use of a few of the fairly recently added ubus calls which I believe were added somewhere between 18 or 19.

Packages hostapd-utils coreutils-timeout php7-cli.

You need to have 802.11r, 802.11k and 802.11v configured and running in the /etc/config/wireless configuration file.

Here is a quick dump of the various settings that are applicable, some (in perticular nassid and r1_key_holder) will need to be changed in your individual case:

```
option mobility_domain 'e612'
option ieee80211r '1'
option ft_psk_generate_local '1'
option pmk_r1_push '1'
option nasid 'CHANGE ME'
option r1_key_holder 'CHANGE ME'
option ft_over_ds '1'
option ieee80211k '1'
option ieee80211v '1'
option bss_transition '1'
```

This is by far not a guide on how to setup 802.11r/k/v on OpenWRT, but there is plenty of info out there on how to get it configured.

**B) Standalone hostapd (in my case Debian Stretch)**

You will want to get hostapd-ct (www.candelatech.com version @ https://github.com/greearb/hostap-ct/tree/master/hostapd) installed. Although possibly unneccsary, the main purpose of having this installed is to get the hostapd_cli show_neighbor command. It's quite possible this will eventually make its way into hostapd (non ct) as its quite useful, but as of March 2020 it has not. This command will dump the currently stored neighbor table, which is useful for getting your own neighbor report entry. There are other ways of getting your own neighbor report entry which I won't cover here. But for what its worth, you can use wpa_cli on the client end to fetch a list of neighbor entries from the currently connected to access point and get it that way.

You will again need to have 802.11r/k/v running on hostapd. I won't get into how to set this up on a raw hostapd instance as there is plenty of info out there.

**C) Common (OpenWRT + Hostapd)**

Your access points must be using the same BSSID. And as mentioned above 802.11r/k/v must be enabled and running.

Not 100% necessary but I have noticed this works quite a bit better with Qualcomm based adapters. Mediatek adapters don't quite bridge the wireless interface correctly and broadcast packets are lost on inital connection. That was at least the case for me. I am running ath9k / ath10k adapters on all floors after replacing the MediaTek based RE650 on my middle floor.

**How it works - Basic Idea**

The script has 3 triggers for when a roam is forced:

**a) Client connected at 2.4ghz, signal strength high** = Try to roam to the same accesspoint, but 5ghz

**b) Client connected at 5ghz, signal strength medium / low** = Try to roam to another access point (you can specify 2.4ghz or 5ghz as it uses a neighbor report entry, so its agnostic to band)

**c) Client connected at 2.4ghz, signal strength low** = Try to roam to another access point (you can specify 2.4ghz or 5ghz as it uses a neighbor report entry, so its agnostic to band)

So in my home, since I have a dual band AC on each floor, as quickly as possible I try to force a roam to 5ghz on the same floor and then wait for 2 b or c to trigger if / when needed. This works well in my case as even at 5ghz, there is a bit of overlay where one AP gets weak and the next one gets strong. So most of the time I am able to roam between 5ghz bands without touching the 2.4ghz band.

**How it works - 802.11v*

Just a quick overview on how 802.11v (as I am using it) works. I am going to butcher the terminology here as wifi internals are not my specialty but heres how it works.

We use beacon requests to ask the client device to send a beacon to an AP. Any AP that supports it (from the provided list). This info is then sent back by the client to the currently connected to AP. Amongst the various information sent back is the RSSI of how strong the request was. This is how we check where we can possible roam to.

We then use BSS-TM to force the client to switch access points.

That's all there is to it.

**Parts of the script**

The script has 3 parts:

**a) wifievent.sh** = A script that is executed via hostapd_cli when a beacon request response comes back from the device so it can be saved and passed back to rssi.php.

**b) rssi.config.php** = A basic confirmation file with various settings

**c) rssi.php** = The infinite loop script that does 2 things: a) Forces the device to roam b) Sends out and waits for a response for beacon requests from client devices.
