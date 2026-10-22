if (!window.__page_initialized__) {
    window.__page_initialized__ = true;
    $(document).ready(function () {
        $('#report-generate').on('click',function(){
            if($('#date-from').val() > $('#date-to').val()){
                return;
            }

            if($('#date-from').val() == '' || $('#date-to').val() == ''){
                return;
            }

            type = $('#report-container').data('transaction-module-type');

            if(type == 'purchase'){
                sub_url = '/js-request/transaction/purchase';
            }else{
                sub_url = '/js-request/transaction/income-expense';
            }


            data = {
                account_id:$('#account').val(),
                budget_id:$('#budget').val(),
                date_from:$('#date-from').val(),
                date_to:$('#date-to').val(),
                transacton_type:type
            };

            $.ajax({
                type: "POST",
                url: base_url + sub_url,
                data: data,
                success: function (response) {
                    data = JSON.parse(response);
                    if(data['success'] == false){
                        $('#report-container-data').html("<p class='error'>"+data['data']['error_message']+"</p>");
                    }else{
                        if(type == 'purchase'){
                            $('#report-container-data').html(data['data']['purchase_container']);
                        }else{
                            $('#report-container-summary').html(data['data']['transaction_summary_container']);
                            $('#report-container-data').html(data['data']['transaction_details_container']);
                        }
                    }
                },
                error: function (xhr, ajaxOptions, thrownError) {
                }
            });
        });

        $('#report-export').on('click', function () {
            let csvData = [];
        
            // Function to extract table data
            function extractTableCSV(table, tableTitle) {
                let data = [];
        
                //Table Title
                if (tableTitle) {
                    data.push(`"${tableTitle}"`); // Add title row
                    data.push(""); // Empty row for spacing
                }
        
                //Table Headers
                let headers = [];
                table.find('thead th').each(function () {
                    headers.push(`"${$(this).text().trim()}"`); // Wrap headers in quotes
                });
                data.push(headers.join(",")); // Add headers row
        
                //Table Body Data
                table.find('tbody tr').each(function () {
                    let row = [];
                    $(this).find('td').each(function () {
                        row.push(`"${$(this).text().trim()}"`); // Extract text and wrap in quotes
                    });
                    data.push(row.join(",")); // Add row data
                });
        
                //Table Footer Data (Optional)
                let footer = [];
                table.find('tfoot tr').each(function () {
                    $(this).find('th').each(function () {
                        footer.push(`"${$(this).text().trim()}"`);
                    });
                    data.push(footer.join(","));
                });
        
                data.push(""); // Add empty line after the table
                return data;
            }
        
            if (type === 'purchase') {
                var content = document.getElementById('report-container-data').innerHTML;

                // Create a new window for printing
                var printWindow = window.open('', '', 'height=600,width=800');

                // Add the content to the new window
                printWindow.document.write('<html><head><title>Purchase Report</title></head><body>');
                printWindow.document.write(content);
                printWindow.document.write('</body></html>');

                // Print the content of the new window
                printWindow.document.close(); // Close the document to apply changes
                printWindow.print(); // Open the print dialog
            }else{
                // Add Summary Table Data
                let summaryTitle = "Summary Table";
                let summaryTable = $('#transaction-summary-table'); // Target summary table
                csvData = csvData.concat(extractTableCSV(summaryTable, summaryTitle));
            
                // Add Transaction Table Data
                let transactionTitle = "Transaction Table";
                let transactionTable = $('#transaction-table'); // Target transaction table
                csvData = csvData.concat(extractTableCSV(transactionTable, transactionTitle));
            
                // Combine all rows into CSV content
                let csvContent = csvData.join("\n");
            
                // Export CSV File
                let blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
                let link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = "report.csv";
                link.click();
            }            
        });
        
    });
}