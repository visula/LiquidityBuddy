$(document).ready(function () {
    var canvas = document.getElementById('cashflow-chart');
    var source = document.getElementById('cashflow-data');

    if (!canvas || !source || typeof Chart === 'undefined') {
        return;
    }

    var data = JSON.parse(source.textContent);
    var money = function (value) {
        return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                { label: 'Money in', data: data.inflow, backgroundColor: 'rgba(25, 135, 84, 0.7)' },
                { label: 'Money out', data: data.outflow, backgroundColor: 'rgba(220, 53, 69, 0.7)' },
                { type: 'line', label: 'Planned dues', data: data.dues, borderColor: '#fd7e14', backgroundColor: '#fd7e14', tension: 0.3 },
                { type: 'line', label: 'Expected income', data: data.expected, borderColor: '#0d6efd', borderDash: [6, 4], tension: 0.3 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return context.dataset.label + ': Rs. ' + money(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: function (value) { return money(value); } }
                }
            }
        }
    });
});
