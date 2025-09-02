<html>
<head>
	<title>stormpigs</title>
	<style type="text/css">
		body { background: black; font-family: arial, geneva, verdana, helvetica, sans-serif; font-size: 8pt; font-color: white;}
		td {COLOR: #666600;  font-family: arial, geneva, verdana, helvetica, sans-serif; font-size: 10pt; font-weight: bold;}
	  	h2 {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 16pt;}
	  	h3 {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 14pt;}
	  	h4 {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 12pt;}
	  	.title {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 24pt; font-weight: bold;}
	  	.pageHeading {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 9pt;}
	  	.pageText {COLOR: #6630dd; font-family: verdana, helvetica, geneva, arial;  font-size: 8pt;}
	  	.footer {COLOR: #666600; font-family: verdana, helvetica, geneva, arial;  font-size: 5pt; text-transform: uppercase;}
	  	.center {margin-left: auto; margin-right: auto;}
		a { font-size: 9pt; font-family: arial, geneva, verdana, helvetica; font-style: italic; }
		a:link		{ COLOR: #6630dd; }
		a:visited	{ COLOR: #6630dd; }
		a:active	{ COLOR: #6630dd; }
		a:hover		{ COLOR: #FFD74D; }
		button {
    		background-color: #007BFF;
    		background-color: #6630dd;
    		color: #ffdd00;
    		border: none;
    		padding: 5px 8px;
    		font-size: 9px;
    		font-weight: bold;
    		cursor: pointer;
    		border-radius: 5px;
    		position: relative;
    		z-index: 10; /* Ensure the button is above any overlapping elements */
    		cursor: pointer; /* Show a pointer to indicate clickability */
		}
    	button:hover {
    	    background-color: #0056b3;
    	}
    </style>
	</style>
        <script language="JavaScript1.2" src="jam.js"></script>
        <script language="JavaScript1.2" src="jamList.js"></script>

        

<!-- Google tag (gtag.js) START -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-MX1FQZ3H0W"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-MX1FQZ3H0W');
</script>
<!-- Google tag (gtag.js) END -->

<?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
<!-- Timeline START -->
<script src="/timeline_2.3.0/timeline_ajax/simile-ajax-api.js" type="text/javascript"></script>
<script src="/timeline_2.3.0/timeline_js/timeline-api.js" type="text/javascript"></script>
    <script>
        SimileAjax.History.enabled = false;
	var tl;
	function formatHalfDecade(date) {
    		const year = date.getFullYear();
    		return `${Math.floor(year / 5) * 5}-${Math.floor(year / 5) * 5 + 4}`;
	}
	function onLoad() 
	{
		var eventSource = new Timeline.DefaultEventSource();
		var theme = Timeline.ClassicTheme.create();
/*		theme.ether.backgroundColors[0] = '#635e5e';
		theme.ether.backgroundColors[1] = '#404040';*/
		theme.ether.backgroundColors[0] = '#5b4a08';
		theme.ether.backgroundColors[1] = '#EFBF04';

		theme.ether.highlightColor = '#E00';
		theme.ether.highlightOpacity = '30';
		theme.event.bubble.width = 360;
		theme.event.bubble.height = 540;
		theme.timeline_start = new Date('1998');
        theme.timeline_stop = new Date('2025');
		var bandInfos = [
			Timeline.createBandInfo({
				eventSource:	eventSource,
				date:		"Apr 14 2011 00:00:00 EST",
				width:		"20%",
				intervalUnit:	Timeline.DateTime.MONTH,
				intervalPixels:	100
			}),
			Timeline.createBandInfo({
				eventSource:	eventSource,
				date:		"May 14 2011 00:00:00 EST",
				width:		"80%",
				intervalUnit:	Timeline.DateTime.YEAR,
				intervalPixels:	60
			})

		];
		bandInfos[1].syncWith = 0;
		bandInfos[1].highlight = true;
		tl = Timeline.create(document.getElementById("my-timeline"), bandInfos);
		tl.loadXML("timeline.xml", function(xml, url) { eventSource.loadXML(xml, url); });
	}
    </script>
<!-- Timeline END -->
<?php endif; ?>
</head>

<?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
    <body onload="onLoad();">
<?php else: ?>
    <body>
<?php endif; ?>

<table border="0" cellpadding="0" cellspacing="0" width="875" class="center">
    <tr>
	<td align="center" valign="top" colspan="7">
		<a href="db/database.php">the database</a>&nbsp;&nbsp;&nbsp;
		<a href="singlesRandomPlayer.php">auto play everything</a>&nbsp;&nbsp;&nbsp;
		<a href="loops.php">loops</A>&nbsp;&nbsp;&nbsp;
		<a href="http://stormpigs.blogspot.com">blog</a>&nbsp;&nbsp;&nbsp;
	</td>
    </tr>
    <tr>
	<td align="left" valign="middle" colspan="7">
		<a href="index.php" style="color:black; font-style:normal;"><font class="title">S &nbsp; T &nbsp; <img src="images/o.gif" height="42" width="42" alt="pig-O" align="absmiddle" border="0"> &nbsp; R &nbsp; M &nbsp; P &nbsp; I &nbsp; G &nbsp; S 	</font></a>
	</td>
    </tr>
    <tr>
	<td align="left" valign="middle" colspan="7">
		<p><br></p>
	</td>
    </tr>
