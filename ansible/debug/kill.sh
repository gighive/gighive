VM=de0c2506-0d5b-44d6-903f-fbe325fabc21
sudo VBoxManage list runningvms
echo
sudo VBoxManage list vms
echo
sudo VBoxManage showvminfo gighive || true
echo
sudo VBoxManage showvminfo $VM || true
echo
sudo VBoxManage storageattach "gighive" \
  --storagectl "SATA Controller" \
  --port 0 --device 0 --medium none || true
echo
sudo VBoxManage storageattach "gighive" \
  --storagectl "SATA Controller" \
  --port 1 --device 0 --medium none || true
echo
sudo VBoxManage unregistervm gighive --delete || sudo VBoxManage unregistervm $VM --delete || true

