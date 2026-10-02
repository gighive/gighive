cd ~/iso-build
ORIG=~/Downloads/iso/ubuntu-24.04.3-live-server-amd64.iso
OUT=ubuntu-24.04.3-live-server-amd64-autoinstall.iso

sudo rm -f "$OUT"

sudo xorriso -as mkisofs \
  -r \
  -V "Ubuntu-Server-Autoinstall" \
  -o "$OUT" \
  --grub2-mbr --interval:local_fs:0s-15s:zero_mbrpt,zero_gpt:"$ORIG" \
  --protective-msdos-label \
  -partition_cyl_align off \
  -partition_offset 16 \
  --mbr-force-bootable \
  -append_partition 2 28732ac11ff8d211ba4b00a0c93ec93b --interval:local_fs:6441216d-6451375d::"$ORIG" \
  -appended_part_as_gpt \
  -iso_mbr_part_type a2a0d0ebe5b9334487c068b6b72699c7 \
  -c '/boot.catalog' \
  -b '/boot/grub/i386-pc/eltorito.img' \
  -no-emul-boot \
  -boot-load-size 4 \
  -boot-info-table \
  --grub2-boot-info \
  -eltorito-alt-boot \
  -e '--interval:appended_partition_2_start_1610304s_size_10160d:all::' \
  -no-emul-boot \
  -boot-load-size 10160 \
  edit


sudo xorriso -indev "$OUT" -report_el_torito as_mkisofs

mkdir -p /tmp/iso-test
sudo mount -o loop,ro "$OUT" /tmp/iso-test
ls -l /tmp/iso-test/nocloud
sudo umount /tmp/iso-test

lsblk -o NAME,SIZE,MODEL,TRAN /dev/sdb /dev/sdc
sudo umount /dev/sdc* 2>/dev/null || true

sudo dd if="$OUT" of=/dev/sdc bs=4M status=progress conv=fsync
sudo sync
