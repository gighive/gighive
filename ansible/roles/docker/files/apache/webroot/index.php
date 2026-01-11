<?php include("header.php"); ?>
</table>
<table cellspacing="5" class="center">
    <tr>
	<td width="200" valign="top">
		<img src="images/homepage/max_50_300.jpg" alt="Max"><img src="images/homepage/src_150_150.jpg" align="top" alt="Stevie">
	</td>
	<td width="25">
	</td>
	<td width="150">
		<img src="images/homepage/tbone_150_300_2.jpg" alt="Tbone">
	</td>
	<td width="25">
	</td>
	<td width="200" valign="bottom">
		<img src="images/homepage/spacer.jpg" align="top"><img src="images/homepage/snufmax_150_50.jpg" align="top" alt="Snuffler and Max"><img src="images/homepage/nick_50_100.jpg" align="top" alt="NickDub"><img src="images/homepage/snuf_150_150sweat.jpg" alt="Snuffler">
	</td>
	<td width="25">
	</td>
	<td width="200">
		<img src="images/homepage/s_combined.jpg" alt="Ankhboy, Stu, Stevie, Sweet, Trebor, 
		Ourance, 
		Stevie">
	</td>
    </tr>
    <tr>
	<td align="right" valign="top" colspan="7">
	</td>
    </tr>
</table>

<table border="0" cellpadding="0" cellspacing="0">
    <tr>
	<td align="left" valign="top" colspan="7">
		<font class="pageHeading">HISTORY OF STORMPIG JAMMING</font>
	</td>
	<td align="left" valign="top">
	</td>
    </tr>
</table>
<div id="my-timeline" style="height: 340px; border: 1px solid #EB0">
<!--div id="my-timeline" class="timeline-default" style="height: 240px; border: 5px solid #aaa"-->
</div>



<div id="contact-us" style="margin-top: 10px;">
	<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
		<font class="pageHeading"><a href="mailto:admin@stormpigs.com">CONTACT US</a></font>

		<font class="pageHeading">
			<span>Powered by </span>
			<a href="https://gighive.app" target="_blank" rel="noopener noreferrer" style="font-style: normal; text-decoration: none;">
				<span>GigHive</span>
				<img src="images/beelogo.png" alt="GigHive" style="height: 28px; width: auto; vertical-align: middle; margin-left: 6px;">
			</a>
		</font>
	</div>
</div>

<script type="text/javascript">
	(function () {
		function adjustContactUs() {
			var timelineHost = document.getElementById('my-timeline');
			var contact = document.getElementById('contact-us');
			if (!timelineHost || !contact) return;
			var overflow = (timelineHost.scrollHeight || 0) - (timelineHost.offsetHeight || 0);
			if (overflow > 0) {
				contact.style.marginTop = (10 + overflow) + 'px';
			}
		}
		if (document.readyState === 'complete') {
			setTimeout(adjustContactUs, 0);
			setTimeout(adjustContactUs, 250);
			setTimeout(adjustContactUs, 1000);
		} else {
			window.addEventListener('load', function () {
				setTimeout(adjustContactUs, 0);
				setTimeout(adjustContactUs, 250);
				setTimeout(adjustContactUs, 1000);
			});
		}
	})();
</script>
</body>
</html>
