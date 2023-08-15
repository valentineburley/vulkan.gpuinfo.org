<?php
/* 		
 *
 * Vulkan hardware capability database server implementation
 *	
 * Copyright (C) 2016-2023 by Sascha Willems (www.saschawillems.de)
 *	
 * This code is free software, you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public
 * License version 3 as published by the Free Software Foundation.
 *	
 * Please review the following information to ensure the GNU Lesser
 * General Public License version 3 requirements will be met:
 * http://www.gnu.org/licenses/agpl-3.0.de.html
 *	
 * The code is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
 * PURPOSE.  See the GNU AGPL 3.0 for more details.		
 *
 */
include 'pagegenerator.php';
PageGenerator::header('About');
?>

<div style="margin-left:50px; width:65%px; padding-bottom: 25px;">
	<h2>About the Vulkan Hardware Database</h2>
	<!-- <p>
		<img src="images/vulkanlogoscene.png" width="320px">
	</p> -->
	<div class="page-header">
		Welcome to the community-driven Vulkan hardware database, an online tool for developers that want to check out
		GPU hardware capabilites for the <a href="https://www.khronos.org/vulkan">new explicit graphics and compute
			API from Khronos</a>.<br><br>
		This database and the client applications to submit reports are developed and maintained by me (<a
			href="http://www.saschawillems.de/" target="_blank">Sascha Willems</a>) in my spare time.<br><br>
		Thanks to the authors of <a href="https://www.datatables.net/" target="_blank">datatables</a> and <a
			href="https://github.com/vedmack/yadcf" target="_blank">yadcf</a> which are both used by the front-end of
		the database.<br><br>
		No profit is made from this data, nor is this data used in any commercial way and no personal data is
		transferred, stored or passed.<br><br>
		If you want to contribute to the development, you can find the source code at <a
			href="https://github.com/SaschaWillems" target="_blank">https://github.com/SaschaWillems</a>.
	</div>
	<!-- <div class="page-header">
		<h2>Donating</h2>
	</div>
	<div>
		All of my tools and the database itself are free-to-use, open source and hosted by me free of charge, feel free to donate ;)
	</div>
	<div>
		<h3>PayPal</h3>
		<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=BHXPMV6ZKPH9E"><img alt="" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif"/></a>
	</div>				 -->

	<h2>Frequently asked questions (FAQ)</h2>
	<div class="faq">
		<div class="entry">
			<p class="question">Q: Who is this database aimed at?</p>
			<p class="answer">A: The database is aimed at graphics developers using the Vulkan graphics api.</p>
		</div>

		<div class="entry">
			<p class="question">Q: How is data gathered?</p>
			<p class="answer">A: Data is submitted manually via a client application called the "Vulkan Hardware Capability
				Viewer". The application runs locally on your device and can be used to view and upload GPU information to the
				database.
				It's available for Windows, Linux, Android, MacOS and iOS and can be downloaded <a
					href="download.php">here</a>.
			</p>
		</div>

		<div class="entry">
			<p class="question">Q: What does "coverage" mean?</p>
			<p class="answer">A: Some listings show percentage values as coverage. This means that the given percentage
				of devices support a given feature or extension at the current date. Coverage includes the latest data
				submitted for that device.</p>
		</div>

		<div class="entry">
			<p class="question">Q: Do the numbers in the database represent market share?</p>
			<p class="answer">A: No, the numbers do <b>in no way represent GPU market shares</b>. The database always
				only stores one report per gpu/os/driver combination. </p>
		</div>

		<div class="entry">
			<p class="question">Q: Is the database available for download?</p>
			<p class="answer">A: No, there is no database download available. I provide access to it through apis, and if you're interested in fetching data from it, please contact me.</p>
		</div>

		<div class="entry">
			<p class="question">Q: Where can I submit feature requests or issues with the database?</p>
			<p class="answer">A: <a href="https://github.com/SaschaWillems/vulkan.gpuinfo.org/issues">https://github.com/SaschaWillems/vulkan.gpuinfo.org/issues</a></p>
		</div>

	</div>
</div>

<?php PageGenerator::footer(); ?>

</body>

</html>