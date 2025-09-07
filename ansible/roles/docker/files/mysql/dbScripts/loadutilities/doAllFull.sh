DEST=~/scripts/gighive/ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full
SCRIPT=mysqlPrep_full.py

echo "going to execute $SCRIPT"
ls -l $SCRIPT
python $SCRIPT
cp -rp prepped_csvs/*.csv $DEST 
echo "DEST is $DEST"
ls -l $DEST
