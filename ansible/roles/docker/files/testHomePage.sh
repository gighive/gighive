echo ipv4Test
curl -4 -kI https://gighive
echo
echo ipv6Test
curl -6 -kI https://gighive
echo
echo ipv6MoreCompleteTest
curl -g -6 -vk  -H "Host: gighive.mysettings.com"  "https://[2600:4040:affe:bb00:c274:2bff:fefc:638c]/"
