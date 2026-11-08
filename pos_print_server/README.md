# PHP POS Print (Local Server)

**A [php](http://php.net/) application for printing POS receipts**

Browser and thermal sale receipts use **# | Product | Quantity | UnitPrice |
Discount | Subtotal**. UnitPrice is the tax-inclusive per-unit price before the
product discount; Discount is the total monetary discount for the row quantity.
The discounted subtotal and invoice totals are unchanged. Hidden-price receipts
omit all three monetary columns. Deploy the updated print server together with
the ERP and restart it to enable the thermal display.

### Installation

 - Copy the **pos_print_server** directory into your local Server document root.
 - Start server from command line with the below command
`php server.php`
 - This app runs at **port 6441 ( ws://localhost:6441 )** and listen for the print jobs.
 - You can simply add `server.sh` file to start the server, so that you will not need to start server manually everytime.
 - You will need to install this script in all computers where you want to print POS receipt.
 - Make sure the logos directory is read-write

