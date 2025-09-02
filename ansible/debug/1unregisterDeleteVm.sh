#!/bin/bash -v
BOX=gighive
VBoxManage list runningvms

VBoxManage controlvm "$BOX" acpipowerbutton
sleep 15 
VBoxManage list runningvms

VBoxManage showvminfo "$BOX" --machinereadable | grep -i VMState=

#VMState="poweroff"
VBoxManage unregistervm "$BOX"

rm -rf ~/VirtualBox\ VMs/$BOX/
