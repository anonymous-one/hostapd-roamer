#!/bin/sh

if [ $2 = "BEACON-RESP-RX" ]; then
        echo "$6" > "/tmp/beaconresp.$3"
fi

if [ $2 = "BEACON-REQ-TX-STATUS" ] && [ "$5" = "ack=0" ]; then
        echo "FAILED" > "/tmp/beaconresp.$3"
fi
