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
    <!-- Modern Timeline Styles -->
    <?php $tl_css_v = @filemtime(__DIR__ . '/timeline/modern-timeline-enhanced.css'); ?>
    <link rel="stylesheet" type="text/css" href="timeline/modern-timeline-enhanced.css<?php echo $tl_css_v ? ('?v=' . $tl_css_v) : ''; ?>">
    
    <!-- Modern Timeline Script -->
    <?php $tl_js_v = @filemtime(__DIR__ . '/timeline/modern-timeline-enhanced.js'); ?>
    <script type="text/javascript" src="timeline/modern-timeline-enhanced.js<?php echo $tl_js_v ? ('?v=' . $tl_js_v) : ''; ?>"></script>
    
    <!-- Modal for event details -->
    <div id="event-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center;">
        <div id="modal-content" style="background: #1a1a1a; border: 2px solid #EB0; border-radius: 8px; padding: 20px; max-width: 600px; max-height: 80%; overflow-y: auto; position: relative;">
            <button id="close-modal" style="position: absolute; top: 10px; right: 15px; background: none; border: none; color: #EB0; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
    </div>
<!-- Modern Timeline END -->
<?php endif; ?>
</head>

<?php if (basename($_SERVER['PHP_SELF']) === 'index.php'): ?>
    <body onload="initStormPigsTimeline();">
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
