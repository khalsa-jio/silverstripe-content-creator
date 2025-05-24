<%-- Template for the Content Generation Report --%>
<div class="content-creator-report">
    <div class="content-creator-report__header">
        <h1>{$Title}</h1>
        <p class="description">{$Description}</p>
    </div>

    <%-- Filter Form --%>
    <div class="content-creator-report__filters">
        {$ReportForm}
    </div>

    <%-- Summary Statistics --%>
    <div class="content-creator-report__summary">
        <div class="content-creator-report__summary-card">
            <div class="title">Total Events</div>
            <div class="value">{$Statistics.TotalEvents}</div>
        </div>
        <div class="content-creator-report__summary-card">
            <div class="title">Total Tokens Used</div>
            <div class="value">{$Statistics.TotalTokens}</div>
        </div>
        <div class="content-creator-report__summary-card">
            <div class="title">Average Processing Time</div>
            <div class="value">{$Statistics.AvgProcessingTime.Nice}s</div>
        </div>
        <div class="content-creator-report__summary-card">
            <div class="title">Success Rate</div>
            <div class="value">{$Statistics.SuccessRate.Nice}%</div>
        </div>
    </div>

    <%-- Chart --%>
    <div class="content-creator-report__chart">
        <canvas id="contentCreatorChart" width="400" height="200"></canvas>
    </div>

    <%-- Data Table --%>
    <div class="content-creator-report__table">
        {$ReportResults}
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartData = JSON.parse('{$ChartData}');

    if (chartData && chartData.dates && chartData.events) {
        const ctx = document.getElementById('contentCreatorChart').getContext('2d');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Events',
                        data: chartData.events,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Tokens',
                        data: chartData.tokens,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of Events'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        },
                        title: {
                            display: true,
                            text: 'Tokens Used'
                        }
                    }
                }
            }
        });
    }
});
</script>
