/* This library builds simple table heads/bodies/footers based on our jams */
/* SCF 1/11/25 - fixing loop directory */
/* SCF 12/20/24 - made all links secure */
/* SCF 4/2/99 */
/* SCF 4/13/99 - updated to add formatting-correct order of formal arguments to ease retiring of songs */  
/* SCF 5/3/99 - changed dateConvert-added listJam function for easier maintenance-keep addSong/addSongNoFile for backward compatibility */  
/* SCF 5/3/99 - added printRow/tableHead print functions for song */  

var serverGeo  = '/audio/';  
var serverLoops  = '/audio/loops/';  
var textSm      = 'Sm';  
var textReg = '';  
var mediaRa = 'ra';  
var mediaMp3 = 'mp3';  
var mediaWav = 'wav';  
var mediaAu = 'au';  
var debug = 0;  
 
var browser = new Object();             // browser ob 
browser.version =  parseInt(navigator.appVersion);  // gets nav app version 
browser.isNS = false;                   // sets isNS to false 
browser.isIE = false;                   // sets isIE to false 
if (navigator.appName.indexOf("Netscape") != -1) 
    browser.isNS = true;                // It's Netscape 
else if (navigator.appName.indexOf("Microsoft") != -1) 
    browser.isIE = true;                // It's IE 
 
function dateConvert(date) {		// a javascript 1.2 function (setMonth)
  
        var today = new Date();  
        var jamDate = new Date();  
  
        var MM = date.substr(0,2);
	if (MM < 10) {
		MM = date.substr(1,1)-1;
	} 
	else { 
		MM = date.substr(0,2)-1;
	}
        var DD  = date.substr(2,2);
	var YYYY;

        if (today.getFullYear() > 1999) {  
            YYYY = ("20" + date.substr(4,2));  
        }  
        else {  
            YYYY = ("19" + date.substr(4,2));  
        }  

        jamDate.setMonth(MM);	// setMonth takes an integer between 0 (Jan) and 11 (Dec)
        jamDate.setDate(DD);  
        jamDate.setYear(YYYY);  
  
        return ( (MM+1) +"/"+ jamDate.getDate() +"/"+  date.substr(4,2));  
}  
function addRowBreak()  
{  
        document.write("\n  <TR>\n\t<TD COLSPAN=\"4\">\n\t\t<P><BR><P>\n\t</TD>\n  </TR>");  
}  
function tableHead(date,compadres) {
        document.write("\n\n\n  <TR>" + 
                        "\n\t<TD ALIGN=\"LEFT\" VALIGN=\"MIDDLE\" COLSPAN=\"4\">" +
//                        "\n\t\t<SPAN id=\"copyHd\">" + dateConvert(date) + "</SPAN>" +
                        "\n\t\t<SPAN id=\"copyHd\">" + date + "</SPAN>" +
                        "\n\t\t<SPAN id=\"names\"> - " + compadres + "</SPAN>" +
                        "\n\t</TD>" +
                        "\n  <TR>");
}
function printRow(date,song,number,media,size) {

	var jamDoc = false;
	var loopDoc = false;
	var label, copy;
	var filename = location.href.indexOf("jams")
//	if ((document.title.indexOf("Welcome") != -1) || (document.title.indexOf("Jams") != -1))
	if (filename > 0 )
		jamDoc = true;
	else 
		loopDoc = true;
//	document.write("\n<tr><td colspan=4> filename is" + filename + "loopDoc is" + loopDoc"</td></tr>");
        if (media == mediaRa) label = 'Real Audio';
        else if (media == mediaWav) label = 'WAV file';
        else if (media == mediaMp3) label = 'MPEG file';
        else label = 'AU Byte';

        if (song == 'extra bit') copy = 'Sm';
        else copy = '';
        
        var linkPartOne = ( date + "_" + number );
        var combinedLink = ( serverLoops + linkPartOne + "." + media );
        console.log(linkPartOne);
        console.log(combinedLink);
		var composeButton = "<button id=\"playButton" + linkPartOne + "\">LOOP AUDIO (5)</button>";
//		var composeButton = "<button id=\"playButton" + linkPartOne + "\" onclick=\"playLoop('" +
  			combinedLink + "', 'playButton" + linkPartOne + "')\">LOOP AUDIO (5)</button>";
		console.log(composeButton);
		        
	if (loopDoc) {
     		document.write("\n  <TR>"+
        		"\n\t<TD ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">\n\t\t<SPAN id=\"copy\">" + song +"</SPAN>\n\t\t<SPAN id=\"copySm\">" + date + "</SPAN>\n\t</TD>");  
        	    document.write("\n\t<TD COLSPAN=\"2\"></TD>"+  
        	         	"\n\t<TD ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">\n\t\t<A HREF=\"" + combinedLink + "\">" + label + "</A>&nbsp;&nbsp;&nbsp;" + composeButton +
        	         	"\n\t</TD>\n  </TR>");  
	}  
	else {
	        if (size != 0) {
       		        document.write("\n  <TR>\n\t<TD></TD>"+
               		        "\n\t<TD ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">\n\t\t<SPAN id=\"copy" + copy + "\">" + song + "</SPAN>\n\t</TD>" + 
                       		"\n\t<TD ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">\n\t\t<A HREF=\"" + serverGeo + date + "_" + number + "." + media + "\">" + label + "</A>\n\t\t<SPAN id=\"copy\">©</SPAN>\n\t</TD>"+  
 	              			"\n\t<TD ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">\n\t\t<SPAN id=\"copySm\">" + size + "KB</SPAN>\n\t</TD>" +
                       		"\n  </TR>");
       		}
		else {
                	document.write("\n  <TR>" +
               	        	"\n\t<TD COLSPAN=\"4\" ALIGN=\"CENTER\" VALIGN=\"MIDDLE\">" + 
                       		"\n\t\t<SPAN id=\"copy" + copy + "\">" + song + "</SPAN>" +
                       		"\n\t</TD>" +
                       		"\n  </TR>");
		}
        }
}

function listJam (jamDate,compadres,media,print,song,songsize) {

        var songArray = new Array();
        var jamArray = new Array();
        var jamDatePos = 0;                             // keep tabs on the new position of the jamDate
        var element = 1;		// song counter
	var loop = false;
	if (compadres == 0)
		loop = true;
	if ( !loop && print) {			// if it's not a loop
	        tableHead(jamDate,compadres);                   // print Jam Heading
	}
        jamArray[0] = listJam.arguments[0];             // add name of new jam to jamArray

        for (var i=4,j=1; i<listJam.arguments.length-1; i += 2,j++) {          // loop from song argument (i==3), then skip 2 to get to next song (i+2)
                var re = /^[0-9]{6}/;                   // regexp which finds the new jam date
                
                if ( re.test(listJam.arguments[i]) ) {  // if regexp tells us its a new jam
			if ( !loop && print) {			// and not a loop
	                        addRowBreak();
                	        tableHead(listJam.arguments[i],listJam.arguments[i+1]);
			}
                        jamArray[element] = listJam.arguments[i];  // add new jam to array
                        element++;              // inc element in jamarray
                        jamDatePos = i;
                        i = i+1;
                        j = 0;
                }
                else {                                  // it's a song
                        if (j == 1 && print) {                   // if first song
				if (element == 1)
	                                printRow(listJam.arguments[jamDatePos],listJam.arguments[i],j,listJam.arguments[i-2],listJam.arguments[i+1]);   // start at fourth arg
				else {
                                	printRow(listJam.arguments[jamDatePos],listJam.arguments[i],j,listJam.arguments[i-1],listJam.arguments[i+1]);   // start at fourth arg
				}
                        }
                        if (j > 1 && print) {                    // if any songs that come after
                                printRow(listJam.arguments[jamDatePos],listJam.arguments[i],j,listJam.arguments[jamDatePos+2],listJam.arguments[i+1]);   // start at fourth arg
                        }
                        songArray[element-1] = j;  // tally number of songs per jam
                }
        }
        var jam = new Array(jamArray,songArray);

	return jam;
}
function countJam(jam) {
	var total = new Array();
	total[0] = jam[0].length;

	var totalSongs = 0;
	for ( var j=0; j < jam[0].length ; j++ ) {
		totalSongs += jam[1][j];
	}
	total[1]=totalSongs;
	return(total);
}
function printJamTotals(totals,songs) {
	addRowBreak();
	document.write("<TR><TD ALIGN=\"RIGHT\" COLSPAN=\"4\"><H4>" + totals[0] + " jams and ");
	document.write(totals[1]);
	if (!songs)
		document.write(" loops");
	else
		document.write(" songs");
	document.write("</H4></TD></TR>");
}
function randomize(num){  
        // from a selection of num numbers, randomize em
        var randomize = Math.floor((Math.random() * 100)) % num;  
        return randomize;  
}  
function randSong(dirArray,location,media) {  

	var randDir = randomize(dirArray[0].length);
        var filename  = randomize(dirArray[1][randDir])+1;

        var table = "<DIV align=\"CENTER\"><TABLE BGCOLOR=\"#BBBB00\" BORDER=\"1\" CELLSPACING=\"1\">"+ 
                        "<TR><TD><TABLE BGCOLOR=\"#c1c1c1\" BORDER=\"1\" CELLPADDING=\"5\" CELLSPACING=\"1\">"+ 
                        "<TR><TD BGCOLOR=\"#000066\"><P STYLE=\"color:#00FF00; text-align: center; text-decoration: blink;\">Song: " +  
                        dirArray[0][randDir] + "_" + filename + "</P></TD></TR></TABLE></TD></TR></TABLE></DIV>"; 
        if (browser.isIE) { 
                document.write("<BGSOUND SRC=\"" + location + dirArray[0][randDir]+ "_" + filename + "." + media + "\" LOOP=\"infinite\">"); 
                document.write(table);
        } 
        else { 
                document.write("<A HREF=\"" + location + dirArray[0][randDir]+ "_" + filename + "." + media + "\">Click to download</A>"); 
                document.write(table); 
        } 
}
