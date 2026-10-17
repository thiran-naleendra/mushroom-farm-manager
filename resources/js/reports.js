import { Chart, BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);
const canvas = document.getElementById('analytics-chart');
const data = JSON.parse(document.getElementById('analytics-chart-data').textContent);
const colors = ['#047857', '#d97706', '#be123c'];
new Chart(canvas, {
    type: 'bar',
    data: { ...data, datasets: data.datasets.map((dataset, index) => ({ ...dataset, backgroundColor: colors[index % colors.length] })) },
    options: { responsive: true, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } } },
});
