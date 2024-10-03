# WebCare Info Logger Plugin

## Description
The WebCare Info Logger plugin allows you to collect and store detailed system information about your WordPress site in JSON format. The plugin gathers essential data, including WordPress version, PHP version, MySQL version, theme information, page/post counts, custom post type counts, active/inactive plugin details, CSS/JS asset counts, database size, media folder size, and user statistics. This data is then stored in a log file, which can be managed from the WordPress admin area. You can view, delete, or clear all logs directly from the plugin's interface.

## How to Use:
1. **Install and Activate the Plugin:**
   Download the plugin, install it via the WordPress dashboard, and activate it.

2. **Access the Plugin Settings:**
   After activation, navigate to "Tools" > "WebCare Info Logger" in your WordPress admin dashboard.

3. **Creating Logs:**
   On the plugin page, click the "Create a New Log" button to generate a new system log. This process will gather data on your site's system setup and store it in the `/log` folder within the plugin's directory.

4. **Viewing Logs:**
   Once a log is created, the plugin displays all the generated logs in a table format. Each entry contains information such as:
   - WordPress version, PHP version, and MySQL version
   - Theme and plugin details
   - Pages, posts, and custom post types (CPT) counts, including published and draft statuses
   - Counts of CSS and JS assets loaded on the main page (without logging in)
   - Size of various WordPress folders (uploads, plugins, media, database)
   - User counts

5. **Log Management:**
   - **Delete a Log:** Each log entry has a "Delete" button, allowing you to remove individual log files.
   - **Clear All Logs:** You can also clear all logs in one action by clicking the "Clear All Logs" button.

6. **Storage Location:**
   All log files are saved in the `/log` folder within the plugin's directory in JSON format. The files are named using your website's URL and the date of log creation.

## Key Features
- Simple system information logging for WordPress.
- Logs can be viewed and managed from the WordPress admin dashboard.
- Automatically counts and logs CSS and JS files loaded on the main page.
- Plugin logs key information such as posts, pages, CPTs, active/inactive plugins, folder sizes, and more.
- Easy log deletion and management functionality.

This plugin is ideal for site administrators who need a convenient way to track their site's system details over time or diagnose performance issues.

[WebCare - WordPress Maintenance](https://webcare.co)
