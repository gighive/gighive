#!/bin/bash
#LIST=("cloud_init" "base" "docker" "post_build_checks" "validate_app")
#for i in ${LIST[@]};do echo $i;ls -l roles/$i/tasks/main.yml;echo;done
FILE=files.txt
ls -1  > files.txt
VER="v3"
SEARCH="\\{\\{ .* \\}\\}"
SEARCH="{{ .* }}"

echo
echo ROLES
ls -l roles

echo
echo SEARCH
for i in $(ls -1 roles/);
do 
    echo
    echo $i
    export PATHMAIN="roles/$i/tasks/main.yml"; ls -l $PATHMAIN
    echo "check for $SEARCH"
    #grep $SEARCH $PATHMAIN
    echo
    export SAVEFILE="backup/${i}Main.yml"
    echo "SAVEFILE=$SAVEFILE"
    cp -rp $PATHMAIN $SAVEFILE
    echo "SAVEFILE=$(ls -l $SAVEFILE)"
    echo
done

echo
echo CONFIG
ls -l ansible.cfg playbooks/site.yml inventories/inventory.yml inventories/group_vars/newlibrary.yml
cp -rp ansible.cfg playbooks/site.yml inventories/inventory.yml inventories/group_vars/newlibrary.yml backup

echo
echo CLOUD_INIT
ls -l roles/cloud_init/files

#ls -1 ansible.cfg.$VER playbooks/site.yml.$VER inventories/inventory.yml.$VER inventories/group_vars/newlibrary*.yml.$VER

echo
echo EXTERNAL_CONFIGS
ls -lA ./roles/docker/files/mysql/externalConfigs/
echo
ls -lA ./roles/docker/files/apache/externalConfigs/

echo
echo TEMPLATES
export TEMPLATE="roles/docker/templates/docker-compose.yml.j2"
cat $TEMPLATE
cp -rp $TEMPLATE backup

echo
echo BACKUP
ls -l backup
exit
# then do this
./checkAll.sh > temp.txt
awk '{print "cp -rp "$1,$1}' temp.txt | sed 's/\.v1$//g' > v1.sh;chmod +x v1.sh
