#!/bin/bash

# This script maps PO and POT files to transifex ressources
# on https://transifex.mandriva.com

if [[ ! -d core && ! -d mds && ! -d pulse2 ]]; then
	echo "Run this script at the top dir of the repository"
	exit 1
fi

which tx > /dev/null
if [ $? -ne 0 ]; then
	echo "Install the transifex client v0.4 (pip install transifex-client==0.4)"
	exit 1
fi

test -d .tx || tx init --host=https://transifex.mandriva.com

[ ! x$1 == x ] && lang="-l $1" && shift 1
args=$@

modules="dyngroup glpi imaging inventory msc pkgs pulse2 backuppc update support monitoring"

for mod in $modules
do
	tx pull -f -r pulse2.${mod} ${lang} ${args}
done
