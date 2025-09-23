import './bootstrap';
import '../css/app.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse'; // Importa el plugin

window.Alpine = Alpine;

Alpine.plugin(collapse); // Registra el plugin

Alpine.start();